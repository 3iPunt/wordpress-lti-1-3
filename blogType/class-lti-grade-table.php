<?php
/**
 * Created by PhpStorm.
 * User: antonibertranbellido
 * Date: 27/12/2018
 * Time: 12:50
 */
require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');

class LTI_Grade_Table extends WP_List_Table {

    private $post_types = array();
    const POST_TYPE_TO_AVOID = array('revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request');

    public function __construct( $args = array() ) {
        $args = wp_parse_args( $args, array(
            'plural' => __('Students', LTIGradesManagement::$DOMAIN),
            'singular' => __('Student', LTIGradesManagement::$DOMAIN),
            'ajax' => false,
            'screen' => null,
        ) );
        $this->get_post_types();
        parent::__construct( $args );
    }

    private function get_post_types() {
        $post_types = get_post_types();
        foreach ($post_types as $post_type) {
            if (!in_array($post_type, self::POST_TYPE_TO_AVOID)) {
                $this->post_types[] = $post_type;
            }
        }
    }

    /**
     * Override the parent columns method. Defines the columns to use in your listing table
     *
     * @return Array
     */
    public function get_columns(){

        $columns = array('student' => __('Student', LTIGradesManagement::$DOMAIN));
        foreach ($this->post_types as $post_type) {
            $columns[$post_type] = sprintf(__('"%s" type', LTIGradesManagement::$DOMAIN), $post_type);
        }

        $columns['comments'] = __('Total comments', LTIGradesManagement::$DOMAIN);

        return $columns;
    }

    /**
     * Prepare the items for the table to process
     *
     * @return Void
     */
    public function prepare_items() {

        $columns = $this->get_columns();
        $hidden = $this->get_hidden_columns();
        $sortable = $this->get_sortable_columns();

        $students = get_users(array('role' =>'subscriber'));
        $data = array();
        foreach ($students as $student) {
            $item = array('ID' => $student->ID, 'student' => $student->display_name);

            foreach ($this->post_types as $post_type) {
                $posts = get_posts(array('post_type' => $post_type, 'author' => $student->ID, 'numberposts' => -1));
                $item[$post_type] = count($posts);
            }

            $args = array(
                'user_id' => $student->ID,
                'count' => true, //Whether to return a comment count (true) or array of comment objects (false).
                'status' => 'all'
            );
            $num_comments = get_comments($args);
            $item['comments'] = $num_comments;

            $data[] = $item;


        }
        usort( $data, array( &$this, 'sort_data' ) );


        $perPage = 20;
        $currentPage = $this->get_pagenum();
        $totalItems = count($data);
        $this->set_pagination_args( array(
            'total_items' => $totalItems,
            'per_page'    => $perPage
        ) );
        $data = array_slice($data,(($currentPage-1)*$perPage),$perPage);
        $this->_column_headers = array($columns, $hidden, $sortable);


        $this->items = $data;
    }


    /**
     * Define which columns are hidden
     *
     * @return Array
     */
    public function get_hidden_columns()
    {
        return array();
    }
    /**
     * Define the sortable columns
     *
     * @return Array
     */
    public function get_sortable_columns()
    {
        return array('student' => array('student', false));
    }


    /**
     * Define what data to show on each column of the table
     *
     * @param  Array $item        Data
     * @param  String $column_name - Current column name
     *
     * @return Mixed
     */
    public function column_default( $item, $column_name )
    {
        if (in_array($column_name, $this->post_types)) {
            return '<a href='.$this->get_page($column_name, $item[ 'ID' ]).' target="_blank">'.$item[ $column_name ].'</a>';
        }
        switch( $column_name ) {
            case 'student':
                return '<a href='.get_author_posts_url($item[ 'ID' ]).' target="_blank">'.$item[ $column_name ].'</a>';
            case 'comments':
                return '<a href='.$this->get_page('comments', $item[ 'ID' ]).' target="_blank">'.$item[ $column_name ].'</a>';
            default:
                return print_r( $item, true ) ;
        }
    }

    private function get_page($type, $user_id) {
        $url = admin_url('admin.php?page=lti_grades_management&type='.$type.'&user_id='.$user_id);
        return $url;
    }

    /**
     * Allows you to sort the data by the variables set in the $_GET
     *
     * @return Mixed
     */
    private function sort_data( $a, $b )
    {
        // Set defaults
        $orderby = 'student';
        $order = 'asc';
        // If orderby is set, use this as the sort column
        if(!empty($_GET['orderby']))
        {
            $orderby = $_GET['orderby'];
        }
        // If order is set use this as the order
        if(!empty($_GET['order']))
        {
            $order = $_GET['order'];
        }
        $result = strcmp( $a[$orderby], $b[$orderby] );
        if($order === 'asc')
        {
            return $result;
        }
        return -$result;
    }
}