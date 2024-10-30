<?php
/**
 * Plugin Name: BetterPortal Theme Embedded
 * Plugin URI: https://github.com/BetterCorp/wordpress-betterportal-embedded
 * Description: Handles embedding BetterPortal.cloud embedded theme in your WP Site
 * Author: BetterCorp
 * Author URI: https://www.bettercorp.dev
 * Version: 1.16
 * License: AGPL-3.0
 * Text Domain: betterportal-theme-embedded
 *
 * Copyright (C) 2016-2024 BetterCorp (PTY) Ltd
 *
 * See LICENSE for more details.
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class BetterPortal_Theme_Embedded
{
    private $defaultHost = 'embedded-theme.betterportal.net';

    public function __construct()
    {
        add_action('init', array($this, 'betterportal_theme_embedded_init'));
        add_action('admin_menu', array($this, 'betterportal_theme_embedded_add_settings_page'));
        add_action('admin_init', array($this, 'betterportal_theme_embedded_register_settings'));
        add_shortcode('betterportal_embed', array($this, 'betterportal_theme_embedded_betterportal_embed_shortcode'));
        add_action('elementor/widgets/widgets_registered', array($this, 'betterportal_theme_embedded_register_elementor_widget'));
        add_action('elementor/elements/categories_registered', array($this, 'betterportal_theme_embedded_add_elementor_widget_category'));
        add_action('save_post', array($this, 'betterportal_theme_embedded_maybe_flush_rules'));
        add_filter('the_content', array($this, 'betterportal_theme_embedded_check_for_shortcode'));
        add_action('admin_init', array($this, 'betterportal_theme_embedded_handle_flush_rewrites'));
        add_action('add_meta_boxes', array($this, 'betterportal_theme_embedded_add_betterportal_meta_box'));
        add_action('save_post', array($this, 'betterportal_theme_embedded_save_betterportal_meta_box'));
        add_action('wp_enqueue_scripts', array($this, 'betterportal_theme_embedded_enqueue_scripts_and_styles'));
        add_action('wp_head', array($this, 'betterportal_theme_embedded_add_preconnect_header'), 1);
    }

    public function betterportal_theme_embedded_init()
    {
        $this->register_betterportal_rewrites();
    }

    public function betterportal_theme_embedded_enqueue_scripts_and_styles()
    {
        if ($this->current_page_has_betterportal()) {
            wp_enqueue_style(
                'betterportal-loader',
                plugins_url('css/betterportal-loader.min.css', __FILE__),
                array(),
                '1.16'
            );
            wp_enqueue_script(
                'betterportal-loader',
                plugins_url('scripts/betterportal-loader.min.js', __FILE__),
                array(),
                '1.16',
                true,
                array('async' => true)
            );
        }
    }

    private function current_page_has_betterportal()
    {
        global $post;
        if (!$post) return false;

        $page_info = $this->get_page_shortcode_info($post->ID);
        return $page_info['shortcodes_count'] > 0;
    }

    public function get_page_shortcode_info($post_id)
    {
        $post = get_post($post_id);
        $shortcodes = $this->get_shortcodes_info($post->post_content);
        $elementor_widgets = $this->get_elementor_widgets_info($post_id);

        return array(
            'shortcodes_count' => $shortcodes['count'] + $elementor_widgets['count'],
            'has_path' => $shortcodes['has_path'] || $elementor_widgets['has_path'],
            'needs_rewrite' => ($shortcodes['count'] - $shortcodes['path_count'] + $elementor_widgets['count'] - $elementor_widgets['path_count']) > 0
        );
    }

    public function register_betterportal_rewrites()
    {
        $pages_with_shortcode = $this->get_pages_with_shortcode();
        foreach ($pages_with_shortcode as $page) {
            $rewrite_enabled = get_post_meta($page['page']->ID, '_betterportal_rewrite_enabled', true);
            if ($page['needs_rewrite'] && $rewrite_enabled !== '0') {
                $page_path = trim(str_replace(home_url(), '', get_permalink($page['page'])), '/');
                add_rewrite_rule(
                    $page_path . '/(.*)$',
                    'index.php?page_id=' . $page['page']->ID,
                    'top'
                );
            }
        }
    }

    public function get_pages_with_shortcode()
    {
        $pages = get_pages();
        $pages_with_shortcode = array();
        foreach ($pages as $page) {
            $shortcodes = $this->get_shortcodes_info($page->post_content);
            $elementor_widgets = $this->get_elementor_widgets_info($page->ID);

            if ($shortcodes['count'] > 0 || $elementor_widgets['count'] > 0) {
                $pages_with_shortcode[] = array(
                    'page' => $page,
                    'shortcodes_count' => $shortcodes['count'] + $elementor_widgets['count'],
                    'has_path' => $shortcodes['has_path'] || $elementor_widgets['has_path'],
                    'needs_rewrite' => ($shortcodes['count'] - $shortcodes['path_count'] + $elementor_widgets['count'] - $elementor_widgets['path_count']) > 0
                );
            }
        }
        return $pages_with_shortcode;
    }

    public function get_shortcodes_info($content)
    {
        $count = 0;
        $path_count = 0;
        $has_path = false;
        if (has_shortcode($content, 'betterportal_embed')) {
            $pattern = '/\[betterportal_embed([^\]]*)\]/';
            preg_match_all($pattern, $content, $matches);
            $count = count($matches[0]);
            foreach ($matches[1] as $attrs) {
                if (strpos($attrs, 'path') !== false) {
                    $path_count++;
                    $has_path = true;
                }
            }
        }
        return array('count' => $count, 'path_count' => $path_count, 'has_path' => $has_path);
    }

    public function get_elementor_widgets_info($post_id)
    {
        $count = 0;
        $path_count = 0;
        $has_path = false;
        if (class_exists('\Elementor\Plugin')) {
            $document = \Elementor\Plugin::$instance->documents->get($post_id);
            if ($document) {
                $data = $document->get_elements_data();
                $result = $this->find_widgets_recursive($data);
                $count = $result['count'];
                $path_count = $result['path_count'];
                $has_path = $result['has_path'];
            }
        }
        return array('count' => $count, 'path_count' => $path_count, 'has_path' => $has_path);
    }

    private function find_widgets_recursive($elements)
    {
        $count = 0;
        $path_count = 0;
        $has_path = false;
        foreach ($elements as $element) {
            if (isset($element['widgetType']) && $element['widgetType'] === 'betterportal_embed') {
                $count++;
                if (isset($element['settings']['path']) && !empty($element['settings']['path'])) {
                    $path_count++;
                    $has_path = true;
                }
            }
            if (isset($element['elements'])) {
                $result = $this->find_widgets_recursive($element['elements']);
                $count += $result['count'];
                $path_count += $result['path_count'];
                $has_path = $has_path || $result['has_path'];
            }
        }
        return array('count' => $count, 'path_count' => $path_count, 'has_path' => $has_path);
    }

    public function betterportal_theme_embedded_maybe_flush_rules($post_id)
    {
        if (wp_is_post_revision($post_id)) {
            return;
        }
        $this->register_betterportal_rewrites();
        flush_rewrite_rules();
    }

    public function betterportal_theme_embedded_check_for_shortcode($content)
    {
        if ($this->page_has_shortcode($content)) {
            $this->betterportal_theme_embedded_maybe_flush_rules(get_the_ID());
        }
        return $content;
    }

    public function page_has_shortcode($content)
    {
        return has_shortcode($content, 'betterportal_embed');
    }

    public function betterportal_theme_embedded_betterportal_embed_shortcode($atts)
    {
        static $instance = 0;
        $instance++;

        $atts = shortcode_atts(array(
            'path' => ''
        ), $atts, 'betterportal_embed');

        return $this->generate_embed_output($atts, $instance);
    }

    public function generate_embed_output($atts, $instance)
    {
        $div_id = esc_attr('betterportal-form-' . $instance);
        $host = $this->get_host();
        $script_url = 'https://' . esc_attr($host) . '/import.js?div=' . $div_id;

        if (!empty($atts['path'])) {
            $script_url .= '&path=' . urlencode($atts['path']);
        }

        $output = '<div data-bpe-wp-import="' . esc_attr($script_url) . '">';
        $output .= '<div id="' . $div_id . '" class="bpe-wp-loader-container">';
        $output .= '<div class="bpe-wp-loader"></div>';
        $output .= '</div>';
        $output .= '</div>';

        return $output;
    }

    public function get_host()
    {
        $options = get_option('betterportal_options');
        return isset($options['host']) && !empty($options['host']) ? $options['host'] : $this->defaultHost;
    }

    public function betterportal_theme_embedded_register_elementor_widget($widgets_manager)
    {
        require_once(__DIR__ . '/widgets/betterportal-embed-widget.php');
        $widgets_manager->register_widget_type(new \BetterPortal_Theme_Embedded_Elementor_Embed_Widget());
    }

    public function betterportal_theme_embedded_add_elementor_widget_category($elements_manager)
    {
        $elements_manager->add_category(
            'betterportal',
            [
                'title' => esc_html__('BetterPortal', 'betterportal-theme-embedded'),
                'icon' => 'fa fa-plug',
            ]
        );
    }

    public function betterportal_theme_embedded_add_settings_page()
    {
        add_options_page(
            'BetterPortal Settings',
            'BetterPortal',
            'manage_options',
            'betterportal-settings',
            array($this, 'render_settings_page')
        );
    }

    public function betterportal_theme_embedded_register_settings()
    {
        register_setting('betterportal_options', 'betterportal_options');

        add_settings_section(
            'betterportal_config_section',
            'Configuration',
            null,
            'betterportal-settings'
        );
        add_settings_field(
            'betterportal_host',
            'BetterPortal Host',
            array($this, 'host_field_callback'),
            'betterportal-settings',
            'betterportal_config_section'
        );
    }

    public function host_field_callback()
    {
        $host = $this->get_host();
        echo "<input type='text' name='betterportal_options[host]' value='" . esc_attr($host) . "' />";
    }

    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['save_rewrite_settings']) && check_admin_referer('betterportal_rewrite_settings', 'betterportal_rewrite_nonce')) {
            $this->handle_rewrite_settings();
        }

        if (isset($_POST['flush_rewrites']) && check_admin_referer('betterportal_rewrite_settings', 'betterportal_rewrite_nonce')) {
            $this->betterportal_theme_embedded_handle_flush_rewrites();
        }

?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <form action="options.php" method="post">
                <?php
                settings_fields('betterportal_options');
                do_settings_sections('betterportal-settings');
                submit_button('Save Configuration');
                ?>
            </form>

            <h2><?php esc_html_e('Pages with BetterPortal Embed', 'betterportal-theme-embedded'); ?></h2>
            <form method="post" action="">
                <?php
                $this->render_pages_section();
                wp_nonce_field('betterportal_rewrite_settings', 'betterportal_rewrite_nonce');
                ?>
                <div style="margin-top: 20px;">
                    <?php
                    submit_button(__('Save Rewrite Settings', 'betterportal-theme-embedded'), 'primary', 'save_rewrite_settings', false);
                    echo ' ';
                    submit_button(__('Flush Rewrite Rules', 'betterportal-theme-embedded'), 'secondary', 'flush_rewrites', false);
                    ?>
                </div>
            </form>
        </div>
<?php
    }

    public function render_pages_section()
    {
        $pages = $this->get_pages_with_shortcode();
        if (empty($pages)) {
            echo '<p>' . esc_html__('No pages with BetterPortal embed found.', 'betterportal-theme-embedded') . '</p>';
            return;
        }

        echo '<table class="widefat" style="margin-bottom: 20px;">';
        echo '<thead><tr><th>' . esc_html__('Page Title', 'betterportal-theme-embedded') . '</th><th>' . esc_html__('URL', 'betterportal-theme-embedded') . '</th><th>' . esc_html__('Embedded Paths', 'betterportal-theme-embedded') . '</th><th>' . esc_html__('Wildcard/URL Linked', 'betterportal-theme-embedded') . '</th></tr></thead>';
        echo '<tbody>';
        foreach ($pages as $page) {
            $edit_link = get_edit_post_link($page['page']->ID);
            $page_url = get_permalink($page['page']->ID);
            $rewrite_enabled = get_post_meta($page['page']->ID, '_betterportal_rewrite_enabled', true);
            $rewrite_enabled = $rewrite_enabled !== '' ? $rewrite_enabled : '1'; // Default to enabled

            $shortcode_info = $this->get_detailed_shortcode_info($page['page']->post_content, $page['page']->ID);

            echo '<tr>';
            echo '<td><a href="' . esc_url($edit_link) . '">' . esc_html($page['page']->post_title) . '</a></td>';
            echo '<td><a href="' . esc_url($page_url) . '" target="_blank">' . esc_url($page_url) . '</a></td>';
            echo '<td>';
            if (!empty($shortcode_info['defined_paths'])) {
                echo '<ul style="margin: 0; padding-left: 20px;">';
                foreach ($shortcode_info['defined_paths'] as $path) {
                    echo '<li>' . esc_html($path) . '</li>';
                }
                echo '</ul>';
            } else {
                esc_html_e('None', 'betterportal-theme-embedded');
            }
            echo '</td>';
            echo '<td>';
            $wildcard_count = $shortcode_info['total_count'] - count($shortcode_info['defined_paths']);
            echo esc_html__('Count:', 'betterportal-theme-embedded') . ' ' . esc_html($wildcard_count);
            if ($wildcard_count > 0) {
                echo '<br>';
                echo '<label><input type="checkbox" name="betterportal_rewrite[' . esc_attr($page['page']->ID) . ']" value="1" ' . checked($rewrite_enabled, '1', false) . '> ' . esc_html__('Enable', 'betterportal-theme-embedded');
                echo ' ' . esc_html__('rewrites', 'betterportal-theme-embedded') . ' <i><u>' . esc_url(str_replace(home_url(), '', $page_url)) . '*</u></i> ' . esc_html__('to', 'betterportal-theme-embedded') . ' <i><u>' . esc_url(str_replace(home_url(), '', $page_url)) . '</u></i></label>';
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private function get_detailed_shortcode_info($content, $post_id)
    {
        $shortcode_info = $this->get_shortcodes_info($content);
        $elementor_info = $this->get_elementor_widgets_info($post_id);

        $total_count = $shortcode_info['count'] + $elementor_info['count'];
        $defined_paths = array_merge(
            $this->extract_shortcode_paths($content),
            $this->extract_elementor_paths($post_id)
        );

        return [
            'total_count' => $total_count,
            'defined_paths' => array_unique($defined_paths)
        ];
    }

    private function extract_shortcode_paths($content)
    {
        $paths = [];
        if (preg_match_all('/\[betterportal_embed[^\]]*path="([^"]*)"[^\]]*\]/', $content, $matches)) {
            $paths = $matches[1];
        }
        return $paths;
    }

    private function extract_elementor_paths($post_id)
    {
        $paths = [];
        if (class_exists('\Elementor\Plugin')) {
            $document = \Elementor\Plugin::$instance->documents->get($post_id);
            if ($document) {
                $data = $document->get_elements_data();
                $paths = $this->find_elementor_paths_recursive($data);
            }
        }
        return $paths;
    }

    private function find_elementor_paths_recursive($elements)
    {
        $paths = [];
        foreach ($elements as $element) {
            if (isset($element['widgetType']) && $element['widgetType'] === 'betterportal_embed') {
                if (isset($element['settings']['path']) && !empty($element['settings']['path'])) {
                    $paths[] = $element['settings']['path'];
                }
            }
            if (isset($element['elements'])) {
                $paths = array_merge($paths, $this->find_elementor_paths_recursive($element['elements']));
            }
        }
        return $paths;
    }

    public function handle_rewrite_settings()
    {
        if (
            !isset($_POST['betterportal_rewrite_nonce']) ||
            !wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST['betterportal_rewrite_nonce'])),
                'betterportal_rewrite_settings'
            )
        ) {
            return;
        }

        $pages = $this->get_pages_with_shortcode();

        foreach ($pages as $page) {
            $enabled = isset($_POST['betterportal_rewrite'][$page['page']->ID]) ? '1' : '0';
            update_post_meta($page['page']->ID, '_betterportal_rewrite_enabled', $enabled);
        }

        $this->register_betterportal_rewrites();
        flush_rewrite_rules();

        add_settings_error('betterportal_messages', 'betterportal_message', esc_html__('Rewrite settings saved and rewrite rules updated.', 'betterportal-theme-embedded'), 'updated');
    }

    public function betterportal_theme_embedded_handle_flush_rewrites()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $this->register_betterportal_rewrites();
        flush_rewrite_rules();
        add_settings_error('betterportal_messages', 'betterportal_message', 'Rewrite rules have been flushed.', 'updated');
    }

    public function betterportal_theme_embedded_add_betterportal_meta_box()
    {
        add_meta_box(
            'betterportal_rewrite_settings',
            'BetterPortal Settings',
            array($this, 'render_betterportal_meta_box'),
            'page',
            'side',
            'default'
        );
    }

    public function render_betterportal_meta_box($post)
    {
        wp_nonce_field('betterportal_rewrite_settings', 'betterportal_rewrite_settings_nonce');

        $rewrite_enabled = get_post_meta($post->ID, '_betterportal_rewrite_enabled', true);
        $rewrite_enabled = $rewrite_enabled !== '' ? $rewrite_enabled : '1'; // Default to enabled

        $page_info = $this->get_page_shortcode_info($post->ID);

        echo '<p><strong>' . esc_html__('Shortcodes/Widgets:', 'betterportal-theme-embedded') . '</strong> ' . esc_html($page_info['shortcodes_count']) . '</p>';

        if ($page_info['needs_rewrite']) {
            echo '<label for="betterportal_rewrite_enabled">';
            echo '<input type="checkbox" id="betterportal_rewrite_enabled" name="betterportal_rewrite_enabled" value="1" ' . checked($rewrite_enabled, '1', false) . '>';
            echo ' ' . esc_html__('Enable rewrites', 'betterportal-theme-embedded') . ' <i><u>' . esc_url(str_replace(home_url(), '', get_permalink($post->ID))) . '*</u></i> ' . esc_html__('to', 'betterportal-theme-embedded') . ' <i><u>' . esc_url(str_replace(home_url(), '', get_permalink($post->ID))) . '</u></i></label>';
        } else {
            echo '<p>' . esc_html__('This page does not need a rewrite rule.', 'betterportal-theme-embedded') . '</p>';
        }
    }

    public function betterportal_theme_embedded_save_betterportal_meta_box($post_id)
    {
        if (
            !isset($_POST['betterportal_rewrite_settings_nonce']) ||
            !wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST['betterportal_rewrite_settings_nonce'])),
                'betterportal_rewrite_settings'
            )
        ) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $rewrite_enabled = isset($_POST['betterportal_rewrite_enabled']) ? '1' : '0';
        update_post_meta($post_id, '_betterportal_rewrite_enabled', $rewrite_enabled);

        // Flush rewrite rules if the setting has changed
        if ($rewrite_enabled !== get_post_meta($post_id, '_betterportal_rewrite_enabled', true)) {
            $this->register_betterportal_rewrites();
            flush_rewrite_rules();
        }
    }

    public function betterportal_theme_embedded_add_preconnect_header()
    {
        $host = $this->get_host();
        echo '<link rel="preconnect" href="https://' . esc_attr($host) . '" crossorigin>';
    }
}

new BetterPortal_Theme_Embedded();
