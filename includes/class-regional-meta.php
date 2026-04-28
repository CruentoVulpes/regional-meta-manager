<?php
/**
 * RegionalMeta class for managing regional page meta (lang, canonical, hreflang).
 *
 * @package RegionalMetaManager
 */


if (!defined('ABSPATH')) {
    exit;
}

class RegionalMeta
{
    public function __construct()
    {
        add_action('add_meta_boxes', [$this, 'addRegionalMetaBox']);
        add_action('save_post', [$this, 'saveRegionalMeta'], 10, 2);
        add_filter('language_attributes', [$this, 'filterLanguageAttributes'], 10, 2);
        
        add_filter('wpseo_canonical', [$this, 'disableYoastCanonical'], 999);
        
        add_filter('redirect_canonical', [$this, 'disableCanonicalRedirect'], 999, 2);
        
        add_action('template_redirect', [$this, 'preventRedirects'], 1);
        add_action('parse_request', [$this, 'handleRegionalPageRequest'], 999);
        
        if (!is_admin()) {
            add_action('template_redirect', [$this, 'startOutputBuffer'], 999);
            add_action('shutdown', [$this, 'finalReplace'], 0);
        }
    }

    public function addRegionalMetaBox(): void
    {
        add_meta_box(
            'regional_meta_box',
            'Региональные настройки',
            [$this, 'renderRegionalMetaBox'],
            ['page', 'post'],
            'normal',
            'high'
        );
    }

    public function renderRegionalMetaBox($post): void
    {
        wp_nonce_field('regional_meta_box', 'regional_meta_box_nonce');

        $lang_attr = get_post_meta($post->ID, '_regional_lang', true);
        $canonical_url = get_post_meta($post->ID, '_regional_canonical', true);
        $transfer_content = get_post_meta($post->ID, '_regional_canonical_transfer_content', true);
        $hreflang_data = get_post_meta($post->ID, '_regional_hreflang', true);
        
        if (!is_array($hreflang_data)) {
            $hreflang_data = [];
        }

        ?>
        <div style="padding: 10px;">
            <table class="form-table" style="width: 100%;">
                <tr>
                    <th scope="row">
                        <label for="regional_lang">HTML Lang атрибут</label>
                    </th>
                    <td>
                        <input 
                            type="text" 
                            id="regional_lang" 
                            name="regional_lang" 
                            value="<?php echo esc_attr($lang_attr); ?>" 
                            placeholder="fr-BE, fr-FR, en-US и т.д."
                            style="width: 100%; max-width: 300px;"
                        />
                        <p class="description">
                            Укажите языковой код для атрибута &lt;html lang="..."&gt;. 
                            Если не указано, будет использован стандартный язык сайта.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="regional_canonical">Canonical URL</label>
                    </th>
                    <td>
                        <input 
                            type="url" 
                            id="regional_canonical" 
                            name="regional_canonical" 
                            value="<?php echo esc_url($canonical_url); ?>" 
                            placeholder="https://site.com/fr-fr/"
                            style="width: 100%; max-width: 500px;"
                        />
                        <p class="description">
                            Укажите канонический URL для этой страницы. 
                            Если не указано, будет использован URL текущей страницы.
                        </p>
                        <p style="margin-top: 10px;">
                            <label>
                                <input type="checkbox" name="regional_canonical_transfer_content" value="1" <?php checked($transfer_content, '1'); ?> />
                                Передавать контент на канонический URL
                            </label>
                        </p>
                        <p class="description" style="margin-top: 5px;">
                            Если канон URL указывает на страницу с собственным контентом, то он показывает свой контент (который задан). 
                            Если включить опцию, то он слижет весь контент один в один.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label>Hreflang ссылки</label>
                    </th>
                    <td>
                        <div id="hreflang-container">
                            <?php
                            if (!empty($hreflang_data)) {
                                foreach ($hreflang_data as $index => $item) {
                                    $this->renderHreflangRow($index, $item['lang'] ?? '', $item['url'] ?? '');
                                }
                            } else {
                                $this->renderHreflangRow(0, '', '');
                            }
                            ?>
                        </div>
                        <button type="button" class="button" id="add-hreflang-row" style="margin-top: 10px;">
                            + Добавить hreflang ссылку
                        </button>
                        <p class="description">
                            Добавьте альтернативные языковые версии этой страницы. 
                            Формат: fr-BE, fr-FR, en-US и т.д.
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <script>
        jQuery(document).ready(function($) {
            let rowIndex = <?php echo !empty($hreflang_data) ? count($hreflang_data) : 1; ?>;
            
            $('#add-hreflang-row').on('click', function() {
                const row = `
                    <div class="hreflang-row" style="display: flex; gap: 10px; margin-bottom: 10px; align-items: center;">
                        <input 
                            type="text" 
                            name="regional_hreflang[${rowIndex}][lang]" 
                            placeholder="fr-BE" 
                            style="width: 150px;"
                        />
                        <input 
                            type="url" 
                            name="regional_hreflang[${rowIndex}][url]" 
                            placeholder="https://site.com/fr-fr/" 
                            style="flex: 1;"
                        />
                        <button type="button" class="button remove-hreflang-row">Удалить</button>
                    </div>
                `;
                $('#hreflang-container').append(row);
                rowIndex++;
            });

            $(document).on('click', '.remove-hreflang-row', function() {
                $(this).closest('.hreflang-row').remove();
            });
        });
        </script>
        <?php
    }

    private function renderHreflangRow(int $index, string $lang, string $url): void
    {
        ?>
        <div class="hreflang-row" style="display: flex; gap: 10px; margin-bottom: 10px; align-items: center;">
            <input 
                type="text" 
                name="regional_hreflang[<?php echo $index; ?>][lang]" 
                value="<?php echo esc_attr($lang); ?>" 
                placeholder="fr-BE" 
                style="width: 150px;"
            />
            <input 
                type="url" 
                name="regional_hreflang[<?php echo $index; ?>][url]" 
                value="<?php echo esc_url($url); ?>" 
                placeholder="https://site.com/fr-fr/" 
                style="flex: 1;"
            />
            <button type="button" class="button remove-hreflang-row">Удалить</button>
        </div>
        <?php
    }

    public function saveRegionalMeta(int $post_id, $post): void
    {
        if (!isset($_POST['regional_meta_box_nonce']) || 
            !wp_verify_nonce($_POST['regional_meta_box_nonce'], 'regional_meta_box')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if (isset($_POST['regional_lang'])) {
            update_post_meta($post_id, '_regional_lang', sanitize_text_field($_POST['regional_lang']));
        } else {
            delete_post_meta($post_id, '_regional_lang');
        }

        if (isset($_POST['regional_canonical']) && !empty($_POST['regional_canonical'])) {
            $canonical_input = trim($_POST['regional_canonical']);
            if (filter_var($canonical_input, FILTER_VALIDATE_URL)) {
                update_post_meta($post_id, '_regional_canonical', esc_url_raw($canonical_input));
            } else {
                delete_post_meta($post_id, '_regional_canonical');
            }
        } else {
            delete_post_meta($post_id, '_regional_canonical');
        }
        
        $transfer_content = isset($_POST['regional_canonical_transfer_content']) && $_POST['regional_canonical_transfer_content'] === '1';
        update_post_meta($post_id, '_regional_canonical_transfer_content', $transfer_content ? '1' : '');
        
        if (isset($_POST['regional_hreflang']) && is_array($_POST['regional_hreflang'])) {
            $hreflang_data = [];
            foreach ($_POST['regional_hreflang'] as $item) {
                if (!empty($item['lang']) && !empty($item['url'])) {
                    $hreflang_data[] = [
                        'lang' => sanitize_text_field($item['lang']),
                        'url' => esc_url_raw($item['url'])
                    ];
                }
            }
            if (!empty($hreflang_data)) {
                update_post_meta($post_id, '_regional_hreflang', $hreflang_data);
            } else {
                delete_post_meta($post_id, '_regional_hreflang');
            }
        } else {
            delete_post_meta($post_id, '_regional_hreflang');
        }
    }

    public function disableYoastCanonical($canonical)
    {
        global $post;
        
        if ($post) {
            $regional_canonical = get_post_meta($post->ID, '_regional_canonical', true);
            if (!empty($regional_canonical) && filter_var($regional_canonical, FILTER_VALIDATE_URL)) {
                return false;
            }
        }

        return $canonical;
    }

    public function handleRegionalPageRequest($wp): void
    {
        if (is_admin()) {
            return;
        }

        if (function_exists('pll_current_language')) {
            return;
        }

        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $request_path = parse_url($request_uri, PHP_URL_PATH);
        
        if (!$request_path || $request_path === '/') {
            return;
        }
        
        $posts = get_posts([
            'post_type' => ['page', 'post'],
            'post_status' => 'publish',
            'numberposts' => -1,
            'meta_query' => [
                [
                    'key' => '_regional_canonical',
                    'compare' => 'EXISTS'
                ]
            ]
        ]);

        foreach ($posts as $found_post) {
            $canonical = get_post_meta($found_post->ID, '_regional_canonical', true);
            if ($canonical && filter_var($canonical, FILTER_VALIDATE_URL)) {
                $canonical_path = parse_url($canonical, PHP_URL_PATH);
                
                if ($canonical_path) {
                    $canonical_path = rtrim($canonical_path, '/');
                    $request_path_clean = rtrim($request_path, '/');
                    
                    if ($canonical_path === $request_path_clean) {
                        $transfer_content = get_post_meta($found_post->ID, '_regional_canonical_transfer_content', true);
                        if (!$transfer_content) {
                            $path_for_lookup = trim($request_path_clean, '/');
                            $existing = $path_for_lookup ? get_page_by_path($path_for_lookup, OBJECT, ['page', 'post']) : null;
                            if ($existing && (int) $existing->ID !== (int) $found_post->ID) {
                                return;
                            }
                        }
                        
                        if ($found_post->post_type === 'page') {
                            $wp->query_vars['pagename'] = get_page_uri($found_post->ID);
                            $wp->query_vars['page_id'] = $found_post->ID;
                            $wp->query_vars['post_type'] = 'page';
                        } else {
                            $wp->query_vars['p'] = $found_post->ID;
                            $wp->query_vars['post_type'] = $found_post->post_type;
                        }
                        break;
                    }
                }
            }
        }
    }

    public function preventRedirects(): void
    {
        global $post;
        
        if (!$post) {
            return;
        }
        
        $has_regional = get_post_meta($post->ID, '_regional_canonical', true);
        
        if ($has_regional && filter_var($has_regional, FILTER_VALIDATE_URL)) {
            remove_action('template_redirect', 'redirect_canonical');
        }
    }

    public function disableCanonicalRedirect($redirect_url, $requested_url)
    {
        if (!$redirect_url) {
            return $redirect_url;
        }

        global $post;
        
        if ($post) {
            $has_regional = get_post_meta($post->ID, '_regional_canonical', true);
            
            if ($has_regional) {
                return false;
            }
        }

        $current_url = $_SERVER['REQUEST_URI'] ?? '';
        $current_path = parse_url($current_url, PHP_URL_PATH);
        $current_path = rtrim($current_path, '/');
        
        $pages = get_posts([
            'post_type' => ['page', 'post'],
            'post_status' => 'publish',
            'numberposts' => -1,
            'meta_query' => [
                [
                    'key' => '_regional_canonical',
                    'compare' => 'EXISTS'
                ]
            ]
        ]);
        
        foreach ($pages as $page) {
            $canonical = get_post_meta($page->ID, '_regional_canonical', true);
            if ($canonical && filter_var($canonical, FILTER_VALIDATE_URL)) {
                $canonical_path = parse_url($canonical, PHP_URL_PATH);
                
                if ($canonical_path) {
                    $canonical_path = rtrim($canonical_path, '/');
                    
                    if ($canonical_path === $current_path) {
                        return false;
                    }
                }
            }
        }
        
        return $redirect_url;
    }

    public function filterLanguageAttributes(string $output, string $doctype): string
    {
        $lang = $this->getLangAttribute();
        if ($lang) {
            return 'lang="' . esc_attr($lang) . '"';
        }
        return $output;
    }

    public function outputRegionalMeta(): void
    {
        global $post;
        
        if (!$post || !is_singular()) {
            return;
        }

        $canonical_url = $this->getCanonicalUrl($post->ID);
        if ($canonical_url && filter_var($canonical_url, FILTER_VALIDATE_URL)) {
            echo '<link rel="canonical" href="' . esc_url($canonical_url) . '">' . PHP_EOL;
        }
        $hreflang_data = $this->getHreflangData($post->ID);
        if (!empty($hreflang_data)) {
            foreach ($hreflang_data as $item) {
                if (!empty($item['lang']) && !empty($item['url'])) {
                    echo '<link rel="alternate" hreflang="' . esc_attr($item['lang']) . '" href="' . esc_url($item['url']) . '">' . PHP_EOL;
                }
            }
        }
    }

    public function startOutputBuffer(): void
    {
        global $post;
        
        if (!$post) {
            return;
        }
        
        $has_regional = get_post_meta($post->ID, '_regional_lang', true) || 
                        get_post_meta($post->ID, '_regional_canonical', true) || 
                        get_post_meta($post->ID, '_regional_hreflang', true);
        
        if (!$has_regional) {
            return;
        }
        
        ob_start([$this, 'replaceInOutput']);
    }

    public function replaceInOutput(string $buffer): string
    {
        global $post;
        
        if (!$post) {
            return $buffer;
        }
        
        $has_regional = get_post_meta($post->ID, '_regional_lang', true) || 
                        get_post_meta($post->ID, '_regional_canonical', true) || 
                        get_post_meta($post->ID, '_regional_hreflang', true);
        
        if (!$has_regional) {
            return $buffer;
        }
        
        if (strpos($buffer, '<html') === false || strpos($buffer, '</head>') === false) {
            return $buffer;
        }

        $lang = $this->getLangAttribute($post->ID);
        if ($lang) {
            $buffer = preg_replace_callback(
                '/<html\s+([^>]*\s+)?lang=["\']([^"\']*)["\']([^>]*>)/i',
                function($matches) use ($lang) {
                    $before = trim($matches[1] ?? '');
                    $after = $matches[3] ?? '>';
                    if (strpos($after, '>') === false) {
                        $after = '>';
                    }
                    if (!empty($before)) {
                        return '<html ' . $before . ' lang="' . esc_attr($lang) . '"' . $after;
                    } else {
                        return '<html lang="' . esc_attr($lang) . '"' . $after;
                    }
                },
                $buffer,
                1
            );
            
            if (strpos($buffer, 'lang="' . esc_attr($lang)) === false && 
                strpos($buffer, "lang='" . esc_attr($lang)) === false &&
                strpos($buffer, '<html') !== false) {
                $buffer = preg_replace(
                    '/(<html)(\s+)([^>]*>)/i',
                    function($matches) use ($lang) {
                        $attrs = trim($matches[3]);
                        if (!empty($attrs)) {
                            return $matches[1] . ' lang="' . esc_attr($lang) . '" ' . $attrs;
                        } else {
                            return $matches[1] . ' lang="' . esc_attr($lang) . '">';
                        }
                    },
                    $buffer,
                    1
                );
            }
        }

        if (preg_match('/(<head[^>]*>)(.*?)(<\/head>)/is', $buffer, $head_matches)) {
            $head_start = $head_matches[1];
            $head_content = $head_matches[2];
            $head_end = $head_matches[3];

            $hreflang_data = $this->getHreflangData($post->ID);
            // Remove every hreflang link from <head> so SEO plugins / duplicates do not leave stale codes;
            // we re-output hreflang only from RMM meta below.
            if (!empty($hreflang_data)) {
                $head_content = preg_replace('/<link[^>]*\bhreflang\s*=\s*["\'][^"\']*["\'][^>]*>/i', '', $head_content);
            }
            
            $canonical_url = $this->getCanonicalUrl($post->ID);
            if ($canonical_url && filter_var($canonical_url, FILTER_VALIDATE_URL)) {
                $head_content = preg_replace('/<link[^>]*\s+rel=["\']canonical["\'][^>]*>/i', '', $head_content);
            }
            
            $meta_tags = '';

            if ($canonical_url && filter_var($canonical_url, FILTER_VALIDATE_URL)) {
                $meta_tags .= '<link rel="canonical" href="' . esc_url($canonical_url) . '">' . PHP_EOL;
            }
            
            if (!empty($hreflang_data)) {
                foreach ($hreflang_data as $item) {
                    if (!empty($item['lang']) && !empty($item['url'])) {
                        $meta_tags .= '<link rel="alternate" hreflang="' . esc_attr($item['lang']) . '" href="' . esc_url($item['url']) . '">' . PHP_EOL;
                    }
                }
            }
            
            if ($meta_tags) {
                if (preg_match_all('/<link[^>]*rel=["\']alternate["\'][^>]*hreflang[^>]*>/i', $head_content, $hreflang_matches, PREG_OFFSET_CAPTURE)) {
                    $last_hreflang = end($hreflang_matches[0]);
                    $position = $last_hreflang[1] + strlen($last_hreflang[0]);
                    $head_content = substr($head_content, 0, $position) . PHP_EOL . $meta_tags . substr($head_content, $position);
                } elseif (preg_match_all('/<link[^>]*rel=["\']alternate["\'][^>]*oembed[^>]*>/i', $head_content, $oembed_matches, PREG_OFFSET_CAPTURE)) {
                    $last_oembed = end($oembed_matches[0]);
                    $position = $last_oembed[1] + strlen($last_oembed[0]);
                    $head_content = substr($head_content, 0, $position) . PHP_EOL . $meta_tags . substr($head_content, $position);
                } elseif (preg_match('/(<meta[^>]*name=["\']description["\'][^>]*>)/i', $head_content, $matches, PREG_OFFSET_CAPTURE)) {
                    $position = $matches[0][1];
                    $head_content = substr($head_content, 0, $position) . $meta_tags . substr($head_content, $position);
                } elseif (preg_match('/(<meta[^>]*>)/i', $head_content, $matches, PREG_OFFSET_CAPTURE)) {
                    $position = $matches[0][1];
                    $head_content = substr($head_content, 0, $position) . $meta_tags . substr($head_content, $position);
                } else {
                    $head_content = $meta_tags . $head_content;
                }
            }
            
            $new_head = $head_start . $head_content . $head_end;
            $buffer = preg_replace('/(<head[^>]*>)(.*?)(<\/head>)/is', $new_head, $buffer, 1);
        }

        return $buffer;
    }

    public function finalReplace(): void
    {
    }

    private function getLangAttribute($post_id = null): ?string
    {
        if (!$post_id) {
            global $post;
            $post_id = $post ? $post->ID : 0;
        }

        if ($post_id) {
            $lang = get_post_meta($post_id, '_regional_lang', true);
            if (!empty($lang)) {
                return $lang;
            }
        }

        return null;
    }

    private function getCanonicalUrl($post_id = null): ?string
    {
        if (!$post_id) {
            global $post;
            $post_id = $post ? $post->ID : 0;
        }

        if ($post_id) {
            $canonical = get_post_meta($post_id, '_regional_canonical', true);
            if (!empty($canonical) && is_string($canonical) && filter_var($canonical, FILTER_VALIDATE_URL)) {
                return $canonical;
            }
        }

        return null;
    }

    private function getHreflangData($post_id = null): array
    {
        if (!$post_id) {
            global $post;
            $post_id = $post ? $post->ID : 0;
        }

        if ($post_id) {
            $hreflang = get_post_meta($post_id, '_regional_hreflang', true);
            if (is_array($hreflang) && !empty($hreflang)) {
                return $hreflang;
            }
        }

        return [];
    }
}
