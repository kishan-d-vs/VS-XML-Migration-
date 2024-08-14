<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class Elementor_Heading_Content_Widget extends \Elementor\Widget_Base {
    public function get_name() {
        return 'heading_content';
    }

    public function get_title() {
        return __('Heading and Content', 'plugin-name');
    }

    public function get_icon() {
        return 'eicon-text';
    }

    public function get_categories() {
        return ['basic'];
    }

    protected function register_controls() {
        $this->start_controls_section(
            'content_section',
            [
                'label' => __('Content', 'plugin-name'),
                'tab' => \Elementor\Controls_Manager::TAB_CONTENT,
            ]
        );

        $this->add_control(
            'heading',
            [
                'label' => __('Heading', 'plugin-name'),
                'type' => \Elementor\Controls_Manager::TEXT,
                'default' => __('Default Heading', 'plugin-name'),
                'placeholder' => __('Enter your heading', 'plugin-name'),
            ]
        );

        $this->add_control(
            'heading_tag',
            [
                'label' => __('Heading Tag', 'plugin-name'),
                'type' => \Elementor\Controls_Manager::SELECT,
                'options' => [
                    'h1' => __('H1', 'plugin-name'),
                    'h2' => __('H2', 'plugin-name'),
                    'h3' => __('H3', 'plugin-name'),
                    'h4' => __('H4', 'plugin-name'),
                    'h5' => __('H5', 'plugin-name'),
                    'h6' => __('H6', 'plugin-name'),
                ],
                'default' => 'h2',
            ]
        );

        $this->add_control(
            'content',
            [
                'label' => __('Content', 'plugin-name'),
                'type' => \Elementor\Controls_Manager::WYSIWYG,
                'default' => __('Default content', 'plugin-name'),
                'placeholder' => __('Enter your content', 'plugin-name'),
            ]
        );

        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        $heading_tag = $settings['heading_tag'];
        ?>
        <div class="elementor-heading-content-widget">
            <<?php echo esc_html($heading_tag); ?>><?php echo $settings['heading']; ?></<?php echo esc_html($heading_tag); ?>>
            <div class="content"><?php echo $settings['content']; ?></div>
        </div>
        <?php
    }
}