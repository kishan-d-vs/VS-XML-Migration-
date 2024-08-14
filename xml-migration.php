<?php
/*
Plugin Name: XML Migration VS
Plugin URI: https://www.vsplash.com/
Description: A plugin for xml data migration.
Version: 1.0
Author: vsplash
Author URI: https://www.vsplash.com/
License: GPL2
*/

function vs_migration_enqueue_scripts()
{
    wp_enqueue_script('vs-migration-ajax', plugin_dir_url(__FILE__) . 'assets/js/vs-migration-ajax.js', [], null, true);
    wp_localize_script('vs-migration-ajax', 'migrationAjax', [
        'ajax_url' => admin_url('admin-ajax.php')
    ]);
}
add_action('admin_enqueue_scripts', 'vs_migration_enqueue_scripts');

add_action('admin_menu', 'vs_xml_migration_add_pages');
function vs_xml_migration_add_pages()
{
    add_menu_page(
        'XML Migration',
        'XML Migration',
        'manage_options',
        'vs-xml-migration',
        'vsblc_xml_migration_options_page',
        'dashicons-migrate',
        100
    );
}

function vsblc_xml_migration_options_page()
{
?>
    <div class="wrap">
        <h1>XML Migration</h1>
        <input type="text" name="api_url" id="api-url" value="https://raw.githubusercontent.com/kishan-d-vs/xml-api/main/api.xml" />
        <button id="vs-migration-button" class="button-primary">XML Migration</button>
        <div id="vs-migration-results"></div>
    </div>
<?php
}

function vs_xml_migration()
{
    ob_start();
    vs_xml_migration_call();
    $output = ob_get_clean();
    echo $output;
    wp_die();
}
add_action('wp_ajax_vs_xml_migration', 'vs_xml_migration');

function vs_xml_migration_call()
{
    $api_Link = isset($_POST['api_Link']) ? $_POST['api_Link'] : "";
    if($api_Link != ""){
        $response = wp_remote_get($api_Link);
        if (is_wp_error($response)) {
            echo 'Unable to retrieve file content.';
            return;
        }

        $file_content = wp_remote_retrieve_body($response);

        if ($file_content) {
            echo htmlspecialchars($file_content);
        } else {
            echo 'No content found.';
        }

        $xml = simplexml_load_string(
            $file_content,
            "SimpleXMLElement",
            LIBXML_NOCDATA
        );

        if ($xml === false) {
            error_log("Failed loading XML:");
            echo '<div class="notice notice-error"><p>Failed loading XML:</p><ul>';
            foreach (libxml_get_errors() as $error) {
                error_log($error->message);
                echo "<li>", htmlspecialchars($error->message), "</li>";
            }
            echo "</ul></div>";
            return;
        }

        $namespaces = $xml->getNamespaces(true);

        foreach ($xml->channel->item as $item) {
            $title = (string) $item->title;
            $link = (string) $item->link;
            $content = (string) $item->children($namespaces["content"])->encoded;
            $parsed_url = parse_url($link);
            $slug = trim($parsed_url["path"], "/");

            error_log("Processing item: $title");
            error_log("Title: $title");
            error_log("Slug: $slug");
            error_log("Content: " . htmlspecialchars($content));

            if (empty($content)) {
                error_log("Content is empty for post: $title");
                continue;
            }

            // Create the post
            $post_id = wp_insert_post([
                "post_title" => wp_strip_all_tags($title),
                "post_content" => "",
                "post_status" => "publish",
                "post_type" => "page",
                "post_name" => $slug,
            ]);

            if ($post_id) {
                // Check if template ID is provided
                if (!empty($_POST["template_id"])) {
                    $template_id = intval($_POST["template_id"]);
                    if ($template_id) {
                        // Copy layout from the provided template ID
                        copy_elementor_template($post_id, $template_id, $title, $content, $item);
                    }
                } else {
                    // Generate Elementor data
                    $elementor_data = generate_elementor_data($title, $content, $item);

                    // Update post with Elementor data
                    update_post_meta(
                        $post_id,
                        "_elementor_data",
                        wp_slash($elementor_data)
                    );
                    update_post_meta($post_id, "_elementor_edit_mode", "builder");
                    update_post_meta($post_id, "_elementor_template_type", "wp-page");
                    update_post_meta($post_id, "_elementor_version", ELEMENTOR_VERSION); // Ensure Elementor version is set
                    update_post_meta($post_id, "_elementor_page_settings", []);
                }

                // Check if the meta key is 'sub_content'
                foreach ($item->children($namespaces["wp"])->postmeta as $meta) {
                    $meta_key = (string) $meta->meta_key;
                    $meta_value = (string) $meta->meta_value;

                    if ($meta_key === 'sub_content') {
                        // Process 'sub_content' separately
                        xml_migration_process_sub_content($meta_value, $post_id);
                    }

                    if ($meta_key === 'local_hide_content') {
                        // Process 'sub_content' separately
                        xml_migration_process_local_hide_content($meta_value, $post_id);
                    }
                    if ($meta_key === 'different_heading') {
                        // Process 'sub_content' separately
                        xml_migration_process_different_heading($meta_value, $post_id);
                    }
                    if ($meta_key === 'add_s_faq_type') {
                        // Process 'sub_content' separately
                        xml_migration_process_add_s_faq_type($meta_value, $post_id);
                    }
                    if ($meta_key === 'different_right_content') {
                        // Process 'sub_content' separately
                        xml_migration_process_different_right_content($meta_value, $post_id);
                    }
                    if ($meta_key === 'different_hide_content') {
                        // Process 'sub_content' separately
                        xml_migration_process_different_hide_content($meta_value, $post_id);
                    }
                    if ($meta_key === 'service_block') {
                        // Process 'sub_content' separately
                        xml_migration_process_service_block($meta_value, $post_id);
                    }
                }

                // Process and update Rank Math SEO fields
                xml_migration_process_rank_math_seo_fields($item, $post_id);

                error_log("Elementor data and Rank Math SEO fields added to post meta for post ID $post_id");
            } else {
                error_log("Failed to create post: $title");
            }
        }
    }
}

function xml_migration_process_sub_content($sub_content, $post_id)
{
    // Load HTML content into DOMDocument
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $sub_content);
    libxml_clear_errors();

    // Process nodes to add appropriate widgets
    $elements = [];
    $body = $dom->getElementsByTagName("body")->item(0);
    if ($body) {
        foreach ($body->childNodes as $childNode) {
            xml_migration_process_node($childNode, $elements, $dom);
        }
    }

    // Create a section with a single column and add all elements to it
    $section = [
        "id" => uniqid(),
        "elType" => "section",
        "settings" => [],
        "elements" => [
            [
                "id" => uniqid(),
                "elType" => "column",
                "settings" => [
                    "_column_size" => 100,
                ],
                "elements" => $elements,
                "isInner" => false,
            ],
        ],
        "isInner" => false,
    ];

    // Get the existing Elementor data
    $elementor_data = get_post_meta($post_id, '_elementor_data', true);
    if ($elementor_data) {
        $elementor_data = json_decode($elementor_data, true);
    } else {
        $elementor_data = [];
    }

    // Append the new section to the existing Elementor data
    $elementor_data[] = $section;

    // Update post meta with the new Elementor data
    update_post_meta($post_id, '_elementor_data', wp_slash(json_encode($elementor_data)));
}

function xml_migration_process_local_hide_content($local_hide_content, $post_id)
{
    // Load HTML content into DOMDocument
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $local_hide_content);
    libxml_clear_errors();

    // Process nodes to add appropriate widgets
    $elements = [];
    $body = $dom->getElementsByTagName("body")->item(0);
    if ($body) {
        foreach ($body->childNodes as $childNode) {
            xml_migration_process_node($childNode, $elements, $dom);
        }
    }

    // Create a section with a single column and add all elements to it
    $section = [
        "id" => uniqid(),
        "elType" => "section",
        "settings" => [],
        "elements" => [
            [
                "id" => uniqid(),
                "elType" => "column",
                "settings" => [
                    "_column_size" => 100,
                ],
                "elements" => $elements,
                "isInner" => false,
            ],
        ],
        "isInner" => false,
    ];


    // Get the existing Elementor data
    $elementor_data = get_post_meta($post_id, '_elementor_data', true);
    if ($elementor_data) {
        $elementor_data = json_decode($elementor_data, true);
    } else {
        $elementor_data = [];
    }

    // Append the new section to the existing Elementor data
    $elementor_data[] = $section;

    // Update post meta with the new Elementor data
    update_post_meta($post_id, '_elementor_data', wp_slash(json_encode($elementor_data)));
}

function xml_migration_process_different_heading($different_heading, $post_id)
{
    // Load HTML content into DOMDocument
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $different_heading);
    libxml_clear_errors();

    // Process nodes to add appropriate widgets
    $elements = [];
    $body = $dom->getElementsByTagName("body")->item(0);
    if ($body) {
        foreach ($body->childNodes as $childNode) {
            xml_migration_process_node($childNode, $elements, $dom);
        }
    }

    // Create a section with a single column and add all elements to it
    $section = [
        "id" => uniqid(),
        "elType" => "section",
        "settings" => [],
        "elements" => [
            [
                "id" => uniqid(),
                "elType" => "column",
                "settings" => [
                    "_column_size" => 100,
                ],
                "elements" => $elements,
                "isInner" => false,
            ],
        ],
        "isInner" => false,
    ];


    // Get the existing Elementor data
    $elementor_data = get_post_meta($post_id, '_elementor_data', true);
    if ($elementor_data) {
        $elementor_data = json_decode($elementor_data, true);
    } else {
        $elementor_data = [];
    }

    // Append the new section to the existing Elementor data
    $elementor_data[] = $section;

    // Update post meta with the new Elementor data
    update_post_meta($post_id, '_elementor_data', wp_slash(json_encode($elementor_data)));
}

function xml_migration_process_add_s_faq_type($add_s_faq_type, $post_id)
{
    // Load HTML content into DOMDocument
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $add_s_faq_type);
    libxml_clear_errors();

    // Process nodes to add appropriate widgets
    $elements = [];
    $body = $dom->getElementsByTagName("body")->item(0);
    if ($body) {
        foreach ($body->childNodes as $childNode) {
            xml_migration_process_node($childNode, $elements, $dom);
        }
    }

    // Create a section with a single column and add all elements to it
    $section = [
        "id" => uniqid(),
        "elType" => "section",
        "settings" => [],
        "elements" => [
            [
                "id" => uniqid(),
                "elType" => "column",
                "settings" => [
                    "_column_size" => 100,
                ],
                "elements" => $elements,
                "isInner" => false,
            ],
        ],
        "isInner" => false,
    ];

    // Get the existing Elementor data
    $elementor_data = get_post_meta($post_id, '_elementor_data', true);
    if ($elementor_data) {
        $elementor_data = json_decode($elementor_data, true);
    } else {
        $elementor_data = [];
    }

    // Append the new section to the existing Elementor data
    $elementor_data[] = $section;

    // Update post meta with the new Elementor data
    update_post_meta($post_id, '_elementor_data', wp_slash(json_encode($elementor_data)));
}

function xml_migration_process_different_right_content($different_right_content, $post_id)
{
    // Load HTML content into DOMDocument
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $different_right_content);
    libxml_clear_errors();

    // Process nodes to add appropriate widgets
    $elements = [];
    $body = $dom->getElementsByTagName("body")->item(0);
    if ($body) {
        foreach ($body->childNodes as $childNode) {
            xml_migration_process_node($childNode, $elements, $dom);
        }
    }

    // Create a section with a single column and add all elements to it
    $section = [
        "id" => uniqid(),
        "elType" => "section",
        "settings" => [],
        "elements" => [
            [
                "id" => uniqid(),
                "elType" => "column",
                "settings" => [
                    "_column_size" => 100,
                ],
                "elements" => $elements,
                "isInner" => false,
            ],
        ],
        "isInner" => false,
    ];

    // Get the existing Elementor data
    $elementor_data = get_post_meta($post_id, '_elementor_data', true);
    if ($elementor_data) {
        $elementor_data = json_decode($elementor_data, true);
    } else {
        $elementor_data = [];
    }

    // Append the new section to the existing Elementor data
    $elementor_data[] = $section;

    // Update post meta with the new Elementor data
    update_post_meta($post_id, '_elementor_data', wp_slash(json_encode($elementor_data)));
}

function xml_migration_process_different_hide_content($different_hide_content, $post_id)
{
    // Load HTML content into DOMDocument
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $different_hide_content);
    libxml_clear_errors();

    // Process nodes to add appropriate widgets
    $elements = [];
    $body = $dom->getElementsByTagName("body")->item(0);
    if ($body) {
        foreach ($body->childNodes as $childNode) {
            xml_migration_process_node($childNode, $elements, $dom);
        }
    }

    // Create a section with a single column and add all elements to it
    $section = [
        "id" => uniqid(),
        "elType" => "section",
        "settings" => [],
        "elements" => [
            [
                "id" => uniqid(),
                "elType" => "column",
                "settings" => [
                    "_column_size" => 100,
                ],
                "elements" => $elements,
                "isInner" => false,
            ],
        ],
        "isInner" => false,
    ];

    // Get the existing Elementor data
    $elementor_data = get_post_meta($post_id, '_elementor_data', true);
    if ($elementor_data) {
        $elementor_data = json_decode($elementor_data, true);
    } else {
        $elementor_data = [];
    }

    // Append the new section to the existing Elementor data
    $elementor_data[] = $section;

    // Update post meta with the new Elementor data
    update_post_meta($post_id, '_elementor_data', wp_slash(json_encode($elementor_data)));
}

function xml_migration_process_service_block($service_block, $post_id)
{
    // Load HTML content into DOMDocument
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $service_block);
    libxml_clear_errors();

    // Process nodes to add appropriate widgets
    $elements = [];
    $body = $dom->getElementsByTagName("body")->item(0);
    if ($body) {
        foreach ($body->childNodes as $childNode) {
            xml_migration_process_node($childNode, $elements, $dom);
        }
    }

    // Create a section with a single column and add all elements to it
    $section = [
        "id" => uniqid(),
        "elType" => "section",
        "settings" => [],
        "elements" => [
            [
                "id" => uniqid(),
                "elType" => "column",
                "settings" => [
                    "_column_size" => 100,
                ],
                "elements" => $elements,
                "isInner" => false,
            ],
        ],
        "isInner" => false,
    ];

    // Get the existing Elementor data
    $elementor_data = get_post_meta($post_id, '_elementor_data', true);
    if ($elementor_data) {
        $elementor_data = json_decode($elementor_data, true);
    } else {
        $elementor_data = [];
    }

    // Append the new section to the existing Elementor data
    $elementor_data[] = $section;

    // Update post meta with the new Elementor data
    update_post_meta($post_id, '_elementor_data', wp_slash(json_encode($elementor_data)));
}

function xml_migration_create_elementor_widget($widget_type, $settings)
{
    return [
        'id' => uniqid(),
        'elType' => 'widget',
        'settings' => $settings,
        'elements' => [],
        'widgetType' => $widget_type,
        'isInner' => false,
    ];
}

function xml_migration_copy_elementor_template($post_id, $template_id, $title, $content, $item)
{
    $template_data = get_post_meta($template_id, '_elementor_data', true);
    if (!$template_data) {
        error_log("No Elementor data found for template ID $template_id");
        return;
    }

    $template_data = json_decode($template_data, true);
    if (!$template_data || !is_array($template_data)) {
        error_log("Invalid Elementor data for template ID $template_id");
        return;
    }

    // Replace the existing heading in the left column of the hero section with our title
    foreach ($template_data as &$section) {
        if ($section['elType'] === 'section' && isset($section['elements'])) {
            foreach ($section['elements'] as &$element) {
                if ($element['elType'] === 'column' && isset($element['elements'])) {
                    foreach ($element['elements'] as &$widget) {
                        if ($widget['elType'] === 'widget' && $widget['widgetType'] === 'heading') {
                            $widget['settings']['title'] = $title;
                        }
                    }
                }
            }
        }
    }

    // Find the section with two columns and update the left column with new content
    foreach ($template_data as &$section) {
        if ($section['elType'] === 'section' && isset($section['elements'])) {
            foreach ($section['elements'] as &$element) {
                if ($element['elType'] === 'column' && isset($element['elements'])) {
                    $left_column = &$element['elements'];
                    $left_column = []; // Clear existing content in the left column

                    // Load new content into a DOMDocument
                    $dom = new DOMDocument();
                    libxml_use_internal_errors(true);
                    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $content);
                    libxml_clear_errors();

                    // Create new widgets based on the new content
                    $new_elements = [];
                    $body = $dom->getElementsByTagName("body")->item(0);
                    if ($body) {
                        foreach ($body->childNodes as $childNode) {
                            xml_migration_process_node($childNode, $new_elements, $dom);
                        }
                    }

                    // Add the new elements to the left column
                    $left_column = array_merge($left_column, $new_elements);

                    // Add info list as accordion
                    xml_migration_add_info_list_accordion($item, $left_column);

                    // Add FAQ accordion for all relevant meta keys
                    xml_migration_add_faq_accordion($item, $left_column);

                    // Add Process List accordion for all relevant meta keys
                    xml_migration_add_process_accordion($item, $left_column);

                    // Process postmeta tags and add content
                    xml_migration_process_postmeta_tags($item, $left_column);

                    // Update the post meta with the modified template data
                    update_post_meta($post_id, '_elementor_data', wp_slash(json_encode($template_data)));
                    update_post_meta($post_id, '_elementor_edit_mode', 'builder');
                    update_post_meta($post_id, '_elementor_template_type', 'wp-page');
                    update_post_meta($post_id, '_elementor_version', ELEMENTOR_VERSION); // Ensure Elementor version is set
                    update_post_meta($post_id, '_elementor_page_settings', []);

                    return; // Exit after processing
                }
            }
        }
    }

    error_log("Template structure not as expected for template ID $template_id");
}

function xml_migration_generate_elementor_data($title, $content, $item)
{
    $elements = [];

    // Load content into a DOMDocument
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $content);
    libxml_clear_errors();

    // Process the content and convert to Elementor elements
    $body = $dom->getElementsByTagName("body")->item(0);
    if ($body) {
        foreach ($body->childNodes as $childNode) {
            xml_migration_process_node($childNode, $elements, $dom);
        }
    }

    // Add info list as accordion
    xml_migration_add_info_list_accordion($item, $elements);

    // Add FAQ accordion for all relevant meta keys
    xml_migration_add_faq_accordion($item, $elements);

    // Add process nodes accordion for all relevant meta keys
    xml_migration_add_process_accordion($item, $elements);

    // Process postmeta tags and add content
    xml_migration_process_postmeta_tags($item, $elements);

    // Create a section with a single column and add all elements to it
    $section = [
        "id" => uniqid(),
        "elType" => "section",
        "settings" => [],
        "elements" => [
            [
                "id" => uniqid(),
                "elType" => "column",
                "settings" => [
                    "_column_size" => 100,
                ],
                "elements" => $elements,
                "isInner" => false,
            ],
        ],
        "isInner" => false,
    ];

    return json_encode([$section]);
}

function xml_migration_process_node($node, &$elements, $dom)
{
    if ($node->nodeType == XML_ELEMENT_NODE) {
        switch ($node->nodeName) {
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                $elements[] = xml_migration_create_elementor_widget("heading", [
                    "title" => $node->textContent,  // Extract only the text content
                    "level" => (int) substr($node->nodeName, 1),
                ]);
                break;
            case 'p':
                // Check for Gravity Form shortcode inside paragraph
                if (preg_match('/\[gravityform.*?\]/', $dom->saveHTML($node), $matches)) {
                    $elements[] = xml_migration_create_elementor_widget("shortcode", [
                        "shortcode" => $matches[0],
                    ]);
                } else {
                    $elements[] = xml_migration_create_elementor_widget("text-editor", [
                        "editor" => $dom->saveHTML($node),
                    ]);
                }
                break;
            case 'ul':
            case 'ol':
                // Save HTML content of the list node
                $list_html = $dom->saveXML($node);
                $elements[] = xml_migration_create_elementor_widget("text-editor", [
                    "editor" => $list_html,
                ]);
                break;
            case 'img':
                $src = $node->getAttribute("src");
                $elements[] = xml_migration_create_elementor_widget("image", [
                    "url" => $src,
                ]);
                break;
            case 'a':
                $href = $node->getAttribute("href");
                $link_content = $dom->saveHTML($node);
                $elements[] = xml_migration_create_elementor_widget("text-editor", [
                    "editor" => '<a href="' . esc_url($href) . '">' . $link_content . '</a>',
                ]);
                break;
            case 'iframe':
                // Directly add the iframe tag without modification
                $iframe_html = $dom->saveHTML($node);
                $elements[] = xml_migration_create_elementor_widget("html", [
                    "html" => $iframe_html,
                ]);
                break;
            default:
                // Process other node types recursively
                foreach ($node->childNodes as $childNode) {
                    xml_migration_process_node($childNode, $elements, $dom);
                }
                break;
        }
    } elseif ($node->nodeType == XML_TEXT_NODE && trim($node->textContent) !== '') {
        $elements[] = xml_migration_create_elementor_widget("text-editor", [
            "editor" => $node->textContent,
        ]);
    }
}


function xml_migration_process_postmeta_tags($item, &$elements)
{
    $namespaces = $item->getNamespaces(true);
    $postmeta = $item->children($namespaces["wp"])->postmeta;

    foreach ($postmeta as $meta) {
        $meta_key = (string) $meta->meta_key;
        $meta_value = (string) $meta->meta_value;

        switch ($meta_key) {
            case 'process_heading':
                $elements[] = xml_migration_create_elementor_widget("heading", [
                    "title" => $meta_value,
                    "level" => 2, // Adjust heading level as needed
                ]);
                break;

            case '_process_heading':
                // Handle _process_heading meta key if needed
                break;

            case 'process_sub_heading':
                $elements[] = xml_migration_create_elementor_widget("text-editor", [
                    "editor" => $meta_value,
                ]);
                break;

            // Add cases for other specific meta keys you want to handle
            // case 'another_meta_key':
            //     $elements[] = create_elementor_widget("widget_type", [
            //         "setting" => $meta_value,
            //     ]);
            //     break;

            default:
                // Handle any other meta keys if needed
                break;
        }
    }

    // Process the bc_accordion shortcodes after all postmeta has been processed
    xml_migration_process_bc_accordion($item, $elements);
}

function xml_migration_add_info_list_accordion($item, &$elements)
{
    // Initialize arrays to hold info list data
    $info_lists_left = [];
    $info_lists_right = [];

    // Loop through postmeta items
    foreach ($item->children("wp", true)->postmeta as $meta) {
        $meta_key = (string) $meta->meta_key;
        $meta_value = (string) $meta->meta_value;

        // Match and parse info_lists and _info_lists keys for left and right accordions
        if (strpos($meta_key, 'info_lists_') === 0) {
            $field = substr($meta_key, strlen('info_lists_'));
            $current_index = intval(substr($field, 0, strpos($field, '_')));
            $sub_field = substr($field, strpos($field, '_') + 1);

            // Handle different sub-fields for left and right accordions
            if ($sub_field === 'title_left' || $sub_field === 'content_left') {
                if (!isset($info_lists_left[$current_index])) {
                    $info_lists_left[$current_index] = [];
                }
                // Handle title extraction for left
                if ($sub_field === 'title_left') {
                    preg_match('/<a\s.*?>(.*?)<\/a>/', $meta_value, $matches);
                    $info_lists_left[$current_index][$sub_field] = isset($matches[1]) ? $matches[1] : '';
                } else {
                    $info_lists_left[$current_index][$sub_field] = $meta_value;
                }
            } elseif ($sub_field === 'title_right' || $sub_field === 'content_right') {
                if (!isset($info_lists_right[$current_index])) {
                    $info_lists_right[$current_index] = [];
                }
                // Handle title extraction for right
                if ($sub_field === 'title_right') {
                    preg_match('/<a\s.*?>(.*?)<\/a>/', $meta_value, $matches);
                    $info_lists_right[$current_index][$sub_field] = isset($matches[1]) ? $matches[1] : '';
                } else {
                    $info_lists_right[$current_index][$sub_field] = $meta_value;
                }
            }
        }
    }

    // Process both left and right info_lists into accordion items
    $accordion_items = [];

    // Process left info_lists
    foreach ($info_lists_left as $info) {
        if (isset($info['title_left']) && isset($info['content_left'])) {
            $accordion_items[] = [
                "item_title" => $info['title_left'],
                "item_content" => $info['content_left'],
                "_id" => uniqid(),
            ];
        }
    }

    // Process right info_lists
    foreach ($info_lists_right as $info) {
        if (isset($info['title_right']) && isset($info['content_right'])) {
            $accordion_items[] = [
                "item_title" => $info['title_right'],
                "item_content" => $info['content_right'],
                "_id" => uniqid(),
            ];
        }
    }

    // Add accordion widget if items exist
    if (!empty($accordion_items)) {
        $elements[] = create_elementor_widget("rds-accordion-widget", [
            "accordion_items" => $accordion_items,
            "element_pack_widget_tooltip_text" => "This is Tooltip for Combined Accordion",
            "element_pack_widget_effect_transition_duration" => "300",
            "element_pack_widget_effect_transition_easing" => "ease-out",
        ]);
    }
}

function xml_migration_process_bc_accordion($item, &$elements)
{
    // Retrieve namespaces
    $namespaces = $item->getNamespaces(true);

    // Initialize an array to hold the accordion items
    $accordion_items = [];

    // Loop through the content and look for `[bc_accordion]` and `[bc_card]` shortcodes
    foreach ($item->children($namespaces["content"])->encoded as $content) {
        $content_value = (string) $content;

        // Log the content being processed
        error_log("Processing content: " . $content_value);

        // Match all `[bc_card title="..."]...[/bc_card]` patterns
        preg_match_all('/\[bc_card title="(.*?)"\](.*?)\[\/bc_card\]/s', $content_value, $matches, PREG_SET_ORDER);

        // Log if matches are found
        error_log("Found " . count($matches) . " matches.");

        foreach ($matches as $match) {
            $title = $match[1];
            $accordion_content = $match[2];

            // Add the extracted title and content to the accordion items array
            $accordion_items[] = [
                "item_title" => $title,
                "item_content" => $accordion_content,
                "_id" => uniqid(),
            ];

            // Log the title and content for each accordion item
            error_log("Accordion item: Title - " . $title . "; Content - " . $accordion_content);
        }
    }

    // If there are any accordion items, create an accordion widget
    if (!empty($accordion_items)) {
        error_log("Creating accordion widget with " . count($accordion_items) . " items.");
        $elements[] = xml_migration_create_elementor_widget("rds-accordion-widget", [
            "accordion_items" => $accordion_items,
            "element_pack_widget_tooltip_text" => "This is Tooltip for Accordion",
            "element_pack_widget_effect_transition_duration" => "300",
            "element_pack_widget_effect_transition_easing" => "ease-out",
        ]);
    } else {
        error_log("No accordion items found.");
    }
}

function xml_migration_add_faq_accordion($item, &$elements)
{
    // Initialize accordion items array
    $accordion_items = [];

    // Retrieve namespaces
    $namespaces = $item->getNamespaces(true);

    // Process FAQ items into accordion items
    foreach ($item->children($namespaces["wp"])->postmeta as $meta) {
        $meta_key = (string) $meta->meta_key;
        $meta_value = (string) $meta->meta_value;

        // Check if the meta key starts with 'add_service_faq_'
        if (strpos($meta_key, 'add_service_faq_') === 0) {
            $parts = explode('_', $meta_key);
            $faq_index = intval($parts[3]);

            // Determine the type of meta data (title or content)
            if (strpos($meta_key, '_add_faq_title') !== false) {
                error_log("Adding FAQ title: $meta_value at index $faq_index");
                $accordion_items[$faq_index]['faq_title'] = $meta_value;
            } elseif (strpos($meta_key, '_add_faq_content') !== false) {
                error_log("Adding FAQ content: $meta_value at index $faq_index");
                $accordion_items[$faq_index]['faq_content'] = $meta_value;
            }
        }
    }

    // Create a single accordion widget with all FAQ items
    $faq_elements = [];
    $accordion_content = [];
    foreach ($accordion_items as $faq) {
        if (isset($faq['faq_title']) && isset($faq['faq_content'])) {
            error_log("Creating accordion item for title: {$faq['faq_title']}");
            $accordion_content[] = [
                "item_title" => $faq['faq_title'],
                "item_content" => $faq['faq_content'],
                "_id" => uniqid(),
            ];
        }
    }

    if (!empty($accordion_content)) {
        $faq_elements[] = create_elementor_widget("rds-accordion-widget", [
            "accordion_items" => $accordion_content,
            "element_pack_widget_tooltip_text" => "This is Tooltip for Combined Accordion",
            "element_pack_widget_effect_transition_duration" => "300",
            "element_pack_widget_effect_transition_easing" => "ease-out",
        ]);
    }

    // Find the indices of the target postmeta and insert the FAQ elements after them
    $insert_indices = [];
    foreach ($elements as $index => $element) {
        if (isset($element['settings']['_meta_key']) && strpos($element['settings']['_meta_key'], 'add_service_faq_') === 0) {
            $insert_indices[] = $index;
        }
    }

    foreach ($insert_indices as $insert_index) {
        array_splice($elements, $insert_index + 1, 0, $faq_elements);
    }

    if (empty($insert_indices)) {
        // If the target postmeta is not found, append FAQ elements at the end
        $elements = array_merge($elements, $faq_elements);
    }

    error_log("Completed adding FAQ accordion items.");
}

function xml_migration_add_process_accordion($item, &$elements)
{
    $accordion_items = [];

    // Loop through postmeta items
    foreach ($item->children("wp", true)->postmeta as $meta) {
        $meta_key = (string) $meta->meta_key;
        $meta_value = (string) $meta->meta_value;

        // Match and parse process_block_x_process_head and process_block_x_process_content keys
        if (preg_match('/^process_block_(\d+)_process_head$/', $meta_key, $matches)) {
            $block_number = intval($matches[1]);
            $accordion_items[$block_number]['process_head'] = $meta_value;
        } elseif (preg_match('/^process_block_(\d+)_process_content$/', $meta_key, $matches)) {
            $block_number = intval($matches[1]);
            $accordion_items[$block_number]['process_content'] = $meta_value;
        }
    }

    // Prepare content for all blocks in a single accordion
    $accordion_content = [];

    foreach ($accordion_items as $block) {
        // Initialize content elements array
        $content_elements = [];

        // Check if 'process_content' key exists and is not empty
        if (isset($block['process_content']) && !empty($block['process_content'])) {
            // Load HTML content into DOMDocument
            $dom = new DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="utf-8" ?>' . $block['process_content']);
            libxml_clear_errors();

            // Process nodes to add appropriate widgets
            $body = $dom->getElementsByTagName("body")->item(0);
            if ($body) {
                foreach ($body->childNodes as $childNode) {
                    process_node($childNode, $content_elements, $dom);
                }
            }
        }

        // Prepare accordion item
        $accordion_item = [
            "item_title" => $block['process_head'],
            "item_content" => $block['process_content'], // Use the processed HTML content directly
            "_id" => uniqid(),
        ];

        // Add to accordion content array
        $accordion_content[] = $accordion_item;
    }

    // Add all blocks as a single accordion widget
    $elements[] = create_elementor_widget("rds-accordion-widget", [
        "accordion_items" => $accordion_content,
        "element_pack_widget_tooltip_text" => "Tooltip Text",
        "element_pack_widget_effect_transition_duration" => "300",
        "element_pack_widget_effect_transition_easing" => "ease-out",
    ]);
}

function xml_migration_process_rank_math_seo_fields($item, $post_id)
{
    $namespaces = $item->getNamespaces(true);
    $rank_math_fields = $item->children($namespaces["wp"])->postmeta;

    foreach ($rank_math_fields as $meta) {
        $meta_key = (string) $meta->meta_key;
        $meta_value = (string) $meta->meta_value;

        if (strpos($meta_key, "rank_math_") === 0) {
            $decoded_value = maybe_unserialize($meta_value);
            update_post_meta($post_id, $meta_key, $decoded_value);
        }
    }
}
?>