<?php
/**
 * Plugin Name: Intuitive Page Navigator for WordPress
 * Description: A simpler, hierarchical page navigator for wp-admin with collapsible parents and level-only pagination. Includes a toggle to replace the default Pages list.
 * Version: 1.1.0
 * Author: Ashley Knowles
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Intuitive_Pages {
    const SLUG = 'intuitive-pages';
    const NONCE_ACTION = 'ip_nonce_action';
    const OPTION_PER_PAGE = 'ip_per_page';

    public function __construct() {
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_ajax_ip_get_children', array($this, 'ajax_get_children'));
        add_action('wp_ajax_ip_find_parent', array($this, 'ajax_find_parent'));

        // Integrate with the default Pages list (edit.php?post_type=page)
        add_filter('views_edit-page', array($this, 'add_pages_view_toggle'));
        add_action('all_admin_notices', array($this, 'maybe_render_in_pages_list'));
    }

    public function register_menu() {
        // Put it under Pages for convenience
        add_submenu_page(
            'edit.php?post_type=page',
            __('Page Navigator', 'ip'),
            __('Navigator', 'ip'),
            'edit_pages',
            self::SLUG,
            array($this, 'render_page')
        );
    }

    public function enqueue_assets($hook) {
        $is_navigator_screen = ($hook === 'pages_page_' . self::SLUG);
        $is_pages_edit_toggle = ( isset($_GET['post_type'], $_GET['ip']) && $_GET['post_type'] === 'page' && $_GET['ip'] == '1' );

        if ( ! $is_navigator_screen && ! $is_pages_edit_toggle ) return;

        wp_enqueue_style('ip-admin', plugins_url('assets/admin.css', __FILE__), array(), '1.1.0');
        wp_enqueue_script('ip-admin', plugins_url('assets/admin.js', __FILE__), array('jquery'), '1.1.0', true);
        wp_localize_script('ip-admin', 'ip', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE_ACTION),
            'strings' => array(
                'loading' => __('Loading…', 'ip'),
                'no_children' => __('No child pages', 'ip'),
                'no_results'  => __('No matching pages', 'ip')
            )
        ));

        if ( $is_pages_edit_toggle ) {
            // Hide the default posts filter + table with CSS when our toggle is on
            $custom_css = '.wrap form#posts-filter{display:none!important;} .wrap .subsubsub .ip-active a{font-weight:700;}';
            wp_add_inline_style('ip-admin', $custom_css);
        }
    }

    public function add_pages_view_toggle($views) {
        // Adds a "Navigator" tab next to "All", "Published", etc.
        $is_active = ( isset($_GET['ip']) && $_GET['ip'] == '1' );
        $url = add_query_arg(array('post_type' => 'page', 'ip' => 1), admin_url('edit.php'));
        $class = $is_active ? ' class="ip-active"' : '';
        $views['ip'] = '<li' . $class . '><a href="' . esc_url($url) . '">' . esc_html__('Navigator', 'ip') . '</a></li>';
        return $views;
    }

    public function maybe_render_in_pages_list() {
        // Render our navigator UI inside the default Pages list screen when ?ip=1
        if ( ! is_admin() ) return;
        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'edit-page' ) return;
        if ( ! current_user_can('edit_pages') ) return;
        if ( ! isset($_GET['ip']) || $_GET['ip'] != '1' ) return;

        echo '<div class="wrap ip-wrap">';
        $this->render_page_contents(true); // true: inline/injected mode
        echo '</div>';
    }

    public function render_page() {
        if ( ! current_user_can('edit_pages') ) return;
        echo '<div class="wrap ip-wrap">';
        echo '<h1>' . esc_html__('Page Navigator', 'ip') . '</h1>';
        $this->render_page_contents(false); // standalone submenu mode
        echo '</div>';
    }

    private function render_page_contents($injected=false) {
        $selected_parent = isset($_GET['parent']) ? intval($_GET['parent']) : 0; // 0 = top-level
        $paged           = max(1, intval($_GET['paged'] ?? 1));
        $per_page        = $this->get_per_page();
        $orderby         = sanitize_text_field($_GET['orderby'] ?? 'menu_order');
        $order           = strtoupper(sanitize_text_field($_GET['order'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

        // Find parents for the current level (children of selected_parent)
        $parent_args = array(
            'post_type'      => 'page',
            'post_status'    => array('publish','draft','pending','future','private'),
            'post_parent'    => $selected_parent,
            'orderby'        => in_array($orderby, array('menu_order','title','date')) ? $orderby : 'menu_order',
            'order'          => $order,
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'suppress_filters' => true,
        );
        $parent_ids = get_posts($parent_args);
        $total_parents = count($parent_ids);
        $total_pages = $per_page ? max(1, (int) ceil($total_parents / $per_page)) : 1;

        // Slice parents for current page (level-only pagination)
        if ( $per_page ) {
            $offset = ($paged - 1) * $per_page;
            $visible_parent_ids = array_slice($parent_ids, $offset, $per_page);
        } else {
            $visible_parent_ids = $parent_ids;
        }

        $parent_page_obj = $selected_parent ? get_post($selected_parent) : null;

        // Controls
        echo '<form method="get" class="ip-controls">';
        if ( $injected ) {
            // When injected on edit.php, maintain the base params
            echo '<input type="hidden" name="post_type" value="page" />';
            echo '<input type="hidden" name="ip" value="1" />';
        } else {
            echo '<input type="hidden" name="post_type" value="page" />';
            echo '<input type="hidden" name="page" value="'. esc_attr(self::SLUG) .'" />';
        }

        echo '<label class="ip-field">';
        echo '<span>' . esc_html__('Parent:', 'ip') . '</span>';
        wp_dropdown_pages(array(
            'post_type'       => 'page',
            'selected'        => $selected_parent,
            'name'            => 'parent',
            'show_option_none'=> __('Top level (no parent)', 'ip'),
            'option_none_value' => 0,
            'sort_column'     => 'menu_order, post_title',
            'echo'            => 1
        ));
        echo '</label>';

        echo '<label class="ip-field">';
        echo '<span>' . esc_html__('Per page (current level):', 'ip') . '</span>';
        echo '<input type="number" min="1" name="per_page" value="'. esc_attr($per_page) .'" />';
        echo '</label>';

        echo '<label class="ip-field">';
        echo '<span>' . esc_html__('Order by:', 'ip') . '</span>';
        echo '<select name="orderby">';
        $opts = array('menu_order' => 'Menu order', 'title' => 'Title', 'date' => 'Date');
        foreach ($opts as $k=>$v) {
            echo '<option value="'.esc_attr($k).'" '.selected($orderby, $k, false).'>'.esc_html__($v, 'ip').'</option>';
        }
        echo '</select>';
        echo '</label>';

        echo '<label class="ip-field">';
        echo '<span>' . esc_html__('Order:', 'ip') . '</span>';
        echo '<select name="order">';
        echo '<option value="ASC" '.selected($order,'ASC',false).'>ASC</option>';
        echo '<option value="DESC" '.selected($order,'DESC',false).'>DESC</option>';
        echo '</select>';
        echo '</label>';

        echo '<button class="button button-primary">'. esc_html__('Apply', 'ip') .'</button>';

        echo '<span class="ip-jump">';
        echo '<input type="search" id="ip-parent-search" placeholder="'. esc_attr__('Jump to parent page…', 'ip') .'" />';
        echo '<button type="button" class="button" id="ip-parent-search-btn">'. esc_html__('Go', 'ip') .'</button>';
        echo '</span>';

        echo '</form>';

        // Breadcrumbs
        echo '<div class="ip-breadcrumbs">';
        $crumbs = $this->build_breadcrumbs($selected_parent);
        foreach ($crumbs as $crumb) {
            echo '<a class="ip-crumb" href="'. esc_url($crumb['url']) .'">'. esc_html($crumb['title']) .'</a>';
            echo '<span class="ip-crumb-sep">›</span>';
        }
        echo '<span class="ip-crumb-current">';
        echo $parent_page_obj ? esc_html(get_the_title($parent_page_obj)) : esc_html__('Top level', 'ip');
        echo '</span>';
        echo '</div>';

        // Summary
        echo '<p class="description">';
        printf( esc_html__('%d parent item(s) at this level. Showing %d per page.', 'ip'), $total_parents, $per_page );
        echo '</p>';

        // Tree list container
        echo '<div id="ip-tree" class="ip-tree">';
        if ( empty($visible_parent_ids) ) {
            echo '<p>'. esc_html__('No pages at this level.', 'ip') .'</p>';
        } else {
            echo '<ul class="ip-level ip-root" data-parent="'. esc_attr($selected_parent) .'">';
            foreach ($visible_parent_ids as $pid) {
                $this->render_tree_item($pid);
            }
            echo '</ul>';
        }
        echo '</div>';

        // Pagination (level-only)
        if ( $total_pages > 1 ) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            $base_url = remove_query_arg(array('paged'), $_SERVER['REQUEST_URI']);
            $this->render_pagination($base_url, $paged, $total_pages);
            echo '</div></div>';
        }
    }

    private function get_per_page() {
        $default = 10;
        if ( isset($_GET['per_page']) ) {
            $pp = max(1, intval($_GET['per_page']));
            return $pp;
        }
        $saved = get_user_meta(get_current_user_id(), self::OPTION_PER_PAGE, true);
        return $saved ? intval($saved) : $default;
    }

    private function build_breadcrumbs($post_id) {
        $crumbs = array();
        $base_url_navigator = add_query_arg(array('post_type' => 'page', 'page' => self::SLUG), admin_url('edit.php'));
        $base_url_injected  = add_query_arg(array('post_type' => 'page', 'ip' => 1), admin_url('edit.php'));

        if ( $post_id && ($post = get_post($post_id)) ) {
            $ancestors = array_reverse(get_post_ancestors($post_id));
            foreach ($ancestors as $aid) {
                $crumbs[] = array(
                    'title' => get_the_title($aid),
                    'url'   => add_query_arg(array('parent' => $aid), $this->is_injected_mode() ? $base_url_injected : $base_url_navigator),
                );
            }
            $crumbs[] = array(
                'title' => get_the_title($post_id),
                'url'   => add_query_arg(array('parent' => $post_id), $this->is_injected_mode() ? $base_url_injected : $base_url_navigator),
            );
        } else {
            $crumbs[] = array(
                'title' => __('Top level', 'ip'),
                'url'   => add_query_arg(array('parent' => 0), $this->is_injected_mode() ? $base_url_injected : $base_url_navigator),
            );
        }
        return $crumbs;
    }

    private function is_injected_mode() {
        return ( isset($_GET['post_type'], $_GET['ip']) && $_GET['post_type'] === 'page' && $_GET['ip'] == '1' );
    }

    private function render_pagination($base_url, $current, $total) {
        $links = paginate_links(array(
            'base' => $base_url . '%_%',
            'format' => '&paged=%#%',
            'current' => $current,
            'total' => $total,
            'type' => 'array',
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
        ));
        if ( is_array($links) ) {
            echo '<span class="pagination-links">';
            foreach ($links as $link) {
                echo $link;
            }
            echo '</span>';
        }
    }

    private function render_tree_item($post_id) {
        $post = get_post($post_id);
        if ( ! $post ) return;

        $child_count = $this->count_children($post_id);
        $has_children = $child_count > 0;
        $edit_link = get_edit_post_link($post_id, '');
        $view_link = get_permalink($post_id);

        echo '<li class="ip-item" data-id="'. esc_attr($post_id) .'">';
        echo '<div class="ip-row">';

        if ( $has_children ) {
            echo '<button type="button" class="button-link ip-toggle" aria-expanded="false" aria-controls="ip-children-'. esc_attr($post_id) .'">+</button>';
        } else {
            echo '<span class="ip-spacer"></span>';
        }

        echo '<span class="ip-title"><a href="'. esc_url($edit_link) .'">'. esc_html(get_the_title($post)) .'</a></span>';
        echo '<span class="ip-meta">';
        echo '<span class="ip-status ip-status-'. esc_attr($post->post_status) .'">'. esc_html(ucfirst($post->post_status)) .'</span>';
        echo ' · ';
        echo '<a href="'. esc_url($view_link) .'" target="_blank">'. esc_html__('View', 'ip') .'</a>';
        echo ' · ';
        echo '<a href="'. esc_url(add_query_arg(array('post_type'=>'page','page'=>self::SLUG,'parent'=>$post_id), admin_url('edit.php'))) .'">'. esc_html__('Show as parent', 'ip') .'</a>';
        echo ' · ';
        echo '<a href="'. esc_url(get_delete_post_link($post_id, '', true)) .'" class="ip-delete">'. esc_html__('Trash', 'ip') .'</a>';
        echo '</span>';

        if ( $has_children ) {
            echo '<span class="ip-count">'. sprintf( _n('%d child', '%d children', $child_count, 'ip'), $child_count ) .'</span>';
        }

        echo '</div>'; // .ip-row

        if ( $has_children ) {
            echo '<ul class="ip-level ip-children" id="ip-children-'. esc_attr($post_id) .'" data-loaded="0" data-parent="'. esc_attr($post_id) .'"></ul>';
        }

        echo '</li>';
    }

    private function count_children($post_id) {
        $children = get_children(array(
            'post_parent' => $post_id,
            'post_type'   => 'page',
            'post_status' => array('publish','draft','pending','future','private'),
            'fields'      => 'ids',
        ));
        return is_array($children) ? count($children) : 0;
    }

    public function ajax_get_children() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if ( ! current_user_can('edit_pages') ) wp_send_json_error('forbidden', 403);

        $parent = isset($_POST['parent']) ? intval($_POST['parent']) : 0;
        if ( ! $parent ) wp_send_json_success(array('html' => '<li class="ip-empty">'. esc_html__('No child pages', 'ip') .'</li>'));

        $orderby = sanitize_text_field($_POST['orderby'] ?? 'menu_order');
        $order   = strtoupper(sanitize_text_field($_POST['order'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

        $children = get_posts(array(
            'post_type'      => 'page',
            'post_status'    => array('publish','draft','pending','future','private'),
            'post_parent'    => $parent,
            'orderby'        => in_array($orderby, array('menu_order','title','date')) ? $orderby : 'menu_order',
            'order'          => $order,
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'suppress_filters' => true,
        ));

        ob_start();
        if ( empty($children) ) {
            echo '<li class="ip-empty">'. esc_html__('No child pages', 'ip') .'</li>';
        } else {
            foreach ($children as $cid) {
                $this->render_tree_item($cid);
            }
        }
        $html = ob_get_clean();
        wp_send_json_success(array('html' => $html));
    }

    public function ajax_find_parent() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if ( ! current_user_can('edit_pages') ) wp_send_json_error('forbidden', 403);
        $q = sanitize_text_field($_POST['q'] ?? '');
        if ( strlen($q) < 2 ) wp_send_json_success(array('results' => array()));

        $matches = get_posts(array(
            'post_type'      => 'page',
            'post_status'    => array('publish','draft','pending','future','private'),
            's'              => $q,
            'posts_per_page' => 10,
            'fields'         => 'ids',
        ));

        $results = array();
        foreach ($matches as $pid) {
            $results[] = array(
                'id'    => $pid,
                'title' => get_the_title($pid),
                'url'   => add_query_arg(array(
                    'post_type' => 'page',
                    // keep context (submenu or injected)
                    $this->is_injected_mode() ? 'ip' : 'page' => $this->is_injected_mode() ? 1 : self::SLUG,
                    'parent'    => $pid
                ), admin_url('edit.php'))
            );
        }
        wp_send_json_success(array('results' => $results));
    }
}

new Intuitive_Pages();
