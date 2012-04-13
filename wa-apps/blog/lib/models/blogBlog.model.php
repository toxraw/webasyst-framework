<?php
class blogBlogModel extends blogItemModel
{
    const STATUS_PRIVATE = 'private';
    const STATUS_PUBLIC = 'public';

    const VERIFY_EXCEPTION_URL_IN_USE = 0;

    protected $table = 'blog_blog';

    public function search($options = array(),$extend_options = array(),$extend_data = array())
    {
        parent::search($options, $extend_options,$extend_data);

        $this->sql_params['where'] = array();
        if (isset($options['blog'])) {
            switch($options['blog']) {
                case 'all':{
                    break;
                }
                case 'published':{
                    $this->sql_params['where'][] = $this->getWhereByField('status',self::STATUS_PUBLIC);
                    break;
                }
                default:{
                    $this->sql_params['where'][] = $this->getWhereByField('id',$options['blog']);
                    break;
                }
            }
        }

        $this->sql_params['order'] = "{$this->table}.sort DESC";

        /**
         * @event search_blogs_backend
         * @event search_blogs_frontend
         * @param array $options
         * @return array
         */
        $res = wa()->event('search_blogs_'.wa()->getEnv(),$options);
        foreach ($res as $plugin=>$plugin_options) {
            foreach ($plugin_options as $properties=>$values) {
                if ($values) {
                    if (!is_array($values)) {
                        $values = array($values);
                    }
                    if (!isset($this->sql_params[$properties])) {
                        $this->sql_params[$properties] = $values;
                    } else {
                        $this->sql_params[$properties] = array_merge($this->sql_params[$properties],$values);
                    }
                }
            }
        }
        return $this;
    }

    public function prepareView($items, $options = array(), $extend_data = array())
    {
        $extend_options = array_merge($this->extend_options, $options);
        $extend_data = array_merge($this->extend_data,(array)$extend_data);

        foreach ($items as &$item) {
            blogHelper::extendIcon($item);
            if (!isset($extend_options['link']) || $extend_options['link']) {
                $item['link'] = blogBlog::getUrl($item, true);
            }
            unset($item);
        }

        if (isset($options['new']) && $options['new']) {
            $post_model = new blogPostModel();
            $posts_update = $post_model->getAddedPostCount(blogHelper::getLastActivity());

            if ($posts_update) {
                foreach ($posts_update as $id => &$count) {
                    if (isset($items[$id])) {
                        $items[$id]['new_post'] = $count;
                    }
                }
            }
        }

        /**
         * Prepare blog data
         * Extend each blog item via plugins data
         * @event prepare_blogs_frontend
         * @event prepare_blogs_backend
         * @param array $items
         * @param int $item.id
         * @return void
         */
        wa()->event('prepare_blogs_'.wa()->getEnv(), $items);

        return $items;
    }

    public function getAvailable($user = true,$fields = array(), $blog_id = null)
    {
        $where = array();
        $blog_rights = true;
        if ($user) {
            if ($user === true) {
                $user = wa()->getUser();
            }
            if (!$user || !$user->isAdmin('blog')) {
                if ($blog_rights = $user->getRights('blog','blog.%')) {
                    $where[] = $this->getWhereByField('id',array_keys($blog_rights));
                } else {
                    return array();
                }
                //do not show public blog with no direct access
                //$where[] = $this->getWhereByField('status',self::STATUS_PUBLIC);
            }
        } else {
            $where[] = $this->getWhereByField('status',self::STATUS_PUBLIC);
        }

        if ($blog_id) {
            $where[] = $this->getWhereByField($this->id,$blog_id);
        }
        $select = implode(', ',$this->setFields($fields, false));
        $blogs = $this->select($select)->where(implode(' OR ',$where))->order('sort')->fetchAll('id');
        foreach ($blogs as $id=>&$blog) {
            if ($user) {
                if ($blog_rights === true) {
                    $blog['rights'] = blogRightConfig::RIGHT_FULL;
                } else {
                    $blog['rights'] = isset($blog_rights[$id])?$blog_rights[$id]:blogRightConfig::RIGHT_READ;
                }
            } else {
                $blog['rights'] = blogRightConfig::RIGHT_READ;
            }
            unset($blog);
        }
        return $this->prepareView($blogs);
    }

    /**
     *
     * @param  $id
     * @param string $value +1,-1
     * @return void
     */
    public function updateQty($id, $value = '')
    {
        $value = (string) $value;
        $this->exec("UPDATE {$this->table} SET qty = qty {$value} WHERE id = i:id", array('id'=>$id));
    }

    private function verifyData($data,$id = null)
    {
        if (!$id) {
            $id = isset($data['id'])?$data['id']:null;
        }

        if (isset($data['url']) && $this->checkUrl($data['url'], $id)) {
            throw new  waException(_w('Blog URL is in use. Please enter another URL'), self::VERIFY_EXCEPTION_URL_IN_USE);
        }

        return $data;
    }

    public function updateById($id, $data, $options = null, $return_object = false)
    {
        $data = $this->verifyData($data,$id);
        return parent::updateById($id, $data, $options, $return_object);
    }

    public function insert($data, $type = 0)
    {
        $data = $this->verifyData($data);
        return parent::insert($data,$type);
    }

    public function sort($id, $sort)
    {
        $blog = $this->getById($id);
        if ($blog) {

            $this->query("UPDATE {$this->table} SET sort = sort - 1 WHERE sort >= i:sort",
            array('sort'=>$blog['sort'],'max_sort'=>$sort));
            $this->query("UPDATE {$this->table} SET sort = sort + 1 WHERE sort >= i:sort",
            array('sort'=>$sort));

            $this->updateById($id, array('sort'=>$sort));
        }
    }

    public function recalculate($ids = array())
    {
        $sql = <<<SQL
		UPDATE {$this->table} AS t
		SET `qty` = (
			SELECT COUNT(blog_post.id)
			FROM blog_post
			WHERE
				blog_post.blog_id = t.id
				AND
				blog_post.status = s:status
		)
SQL;
        if ($ids) {
            $sql .= "WHERE t.id IN (:ids)";
        }
        $this->query($sql,array('status'=>blogPostModel::STATUS_PUBLISHED,'ids'=>(array)$ids));
    }

    public function getBySlug($slug, $public_only = false,$fields = false)
    {
        $where = array();
        $where[] = $this->getWhereByField('url',$slug);
        if ($public_only) {
            $where[] = $this->getWhereByField('status',self::STATUS_PUBLIC);
        }
        $items = $this->select($fields?implode(', ',(array)$fields):'*')->where(implode(' AND ',$where))->fetchAll($this->id);
        return current($items);
    }

    /**
     * Delete records from table by primary key
     *
     * @param $value
     * @return bool
     */
    public function deleteByField($field, $value = null)
    {
        $items = $this->getByField($field,$value,$this->id);
        $keys = array_keys($items);
        $res = parent::deleteByField($field, $value);
        if ($res) {
            $post_model = new blogPostModel();
            $post_model->deleteByField('blog_id', $keys);
        }
        return $res;
    }

    /**
     * Get settlements of blog without blog url (aka slug)
     *
     * @param array $blog
     * @return array array of key-value storages where 'single' key means if only this blog settled to url and 'url' means pure url (without slug)
     */
    static public function getPureSettlements($blog)
    {
        if (isset($blog['url'])) {
            unset($blog['url']);
        }

        $settlements = array();
        $urls = blogBlog::getUrl($blog, true);

        foreach ($urls as &$url) {
            if (strpos($url, '%blog_url%') === false) {
                $settlements[] = array(
    				'single' => true,
    				'url' => $url
                );
            } else {
                $settlements[] = array(
					'single' => false,
					'url' => str_replace('%blog_url%/', '', $url)
                );
            }
        }
        unset($url);

        return $settlements;
    }

    public function genUniqueUrl($from)
    {
        $from = preg_replace('/\s+/', '-', $from);
        $url = blogHelper::transliterate($from);

        if (strlen($url) == 0) {
            $url = rand(1, 10000);
        }

        $pattern = $this->escape($url, 'like') . '%';

        $alike = $this->query("SELECT url FROM " .
        $this->getTableName() .
    			" WHERE url LIKE '$pattern'" .
    			" ORDER BY LENGTH(url)")
        ->fetchAll('url');

        // if exists than get last and append random digit
        if (is_array($alike) && isset($alike[$url])) {
            $last = array_pop($alike);
            $url = $last['url'] . rand(0, 9);
        }

        return $url;

    }

}