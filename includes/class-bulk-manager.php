<?php
if (!defined('ABSPATH')) {
    exit;
}

class RMMBulkManager
{
    const NONCE_ACTION    = 'rmm_bulk_manager';
    const SUPPORTED_TYPES = ['page', 'post'];

    public function __construct()
    {
        add_action('admin_menu',  [$this, 'addAdminPage']);
        add_action('wp_ajax_rmm_bulk_save',      [$this, 'ajaxBulkSave']);
        add_action('wp_ajax_rmm_domain_replace', [$this, 'ajaxDomainReplace']);
        add_action('wp_ajax_rmm_lang_replace',   [$this, 'ajaxLangReplace']);
        add_action('admin_enqueue_scripts',      [$this, 'enqueueScripts']);
    }

    public function addAdminPage(): void
    {
        add_management_page(
            'RMM Bulk Meta',
            'RMM Bulk Meta',
            'manage_options',
            'rmm-bulk-manager',
            [$this, 'renderPage']
        );
    }

    public function enqueueScripts(string $hook): void
    {
        if ($hook !== 'tools_page_rmm-bulk-manager') {
            return;
        }
        wp_enqueue_script('jquery');
    }

    private function getAllPosts(): array
    {
        return get_posts([
            'post_type'   => self::SUPPORTED_TYPES,
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby'     => ['post_type' => 'ASC', 'post_title' => 'ASC'],
        ]);
    }

    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }

        $posts = $this->getAllPosts();
        $nonce = wp_create_nonce(self::NONCE_ACTION);
        ?>
        <div class="wrap">
            <h1>RMM — Массовое управление мета</h1>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin:20px 0;">

                <div style="background:#fff;border:1px solid #c3c4c7;padding:20px 24px;border-radius:4px;">
                    <h2 style="margin-top:0;">Автозамена домена</h2>
                    <p style="color:#50575e;margin-top:0;">Заменяет или генерирует canonical / hreflang URL по всему сайту.</p>
                    <table class="form-table" style="margin-bottom:12px;">
                        <tr>
                            <th style="width:140px;padding:6px 10px 6px 0;"><label for="rmm-old-domain">Старый домен</label></th>
                            <td style="padding:4px 0;">
                                <input type="text" id="rmm-old-domain" placeholder="https://old-domain.com" style="width:100%;max-width:320px;" />
                                <p class="description" style="margin:2px 0 0;">Оставьте пустым, чтобы только генерировать</p>
                            </td>
                        </tr>
                        <tr>
                            <th style="padding:6px 10px 6px 0;"><label for="rmm-new-domain">Новый домен</label></th>
                            <td style="padding:4px 0;"><input type="text" id="rmm-new-domain" placeholder="https://new-domain.com" style="width:100%;max-width:320px;" /></td>
                        </tr>
                        <tr>
                            <th style="padding:6px 10px 6px 0;"></th>
                            <td style="padding:4px 0;">
                                <label><input type="checkbox" id="rmm-replace-hreflang-url" checked /> Также заменить в hreflang URL</label><br>
                                <label><input type="checkbox" id="rmm-generate-missing" /> Сгенерировать canonical для страниц без него (новый домен + путь страницы)</label>
                            </td>
                        </tr>
                    </table>
                    <button class="button button-primary" id="rmm-auto-replace">Применить</button>
                    <span id="rmm-replace-status" style="margin-left:10px;font-weight:500;display:block;margin-top:6px;min-height:1.4em;"></span>
                </div>

                <div style="background:#fff;border:1px solid #c3c4c7;padding:20px 24px;border-radius:4px;">
                    <h2 style="margin-top:0;">Автозамена языкового кода</h2>
                    <p style="color:#50575e;margin-top:0;">Заменяет lang-код в атрибуте HTML и/или в кодах hreflang по всему сайту.</p>
                    <table class="form-table" style="margin-bottom:12px;">
                        <tr>
                            <th style="width:140px;padding:6px 10px 6px 0;"><label for="rmm-old-lang">Старый код</label></th>
                            <td style="padding:4px 0;"><input type="text" id="rmm-old-lang" placeholder="fr-BE" style="width:140px;" /></td>
                        </tr>
                        <tr>
                            <th style="padding:6px 10px 6px 0;"><label for="rmm-new-lang">Новый код</label></th>
                            <td style="padding:4px 0;"><input type="text" id="rmm-new-lang" placeholder="fr-FR" style="width:140px;" /></td>
                        </tr>
                        <tr>
                            <th style="padding:6px 10px 6px 0;"></th>
                            <td style="padding:4px 0;">
                                <label><input type="checkbox" id="rmm-replace-html-lang" checked /> HTML lang атрибут</label><br>
                                <label><input type="checkbox" id="rmm-replace-hreflang-code" checked /> Коды в hreflang</label>
                            </td>
                        </tr>
                    </table>
                    <button class="button button-primary" id="rmm-lang-replace">Применить</button>
                    <span id="rmm-lang-status" style="margin-left:10px;font-weight:500;display:block;margin-top:6px;min-height:1.4em;"></span>
                </div>

            </div>

            <div style="background:#fff;border:1px solid #c3c4c7;padding:20px 24px;margin:20px 0;border-radius:4px;">
                <h2 style="margin-top:0;">Ручное редактирование</h2>
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
                    <input type="text" id="rmm-filter" placeholder="Фильтр по названию или пути..." style="width:300px;" />
                    <button class="button button-primary" id="rmm-save-all">Сохранить всё</button>
                    <span id="rmm-save-status" style="font-weight:500;"></span>
                </div>
                <table class="wp-list-table widefat fixed striped" id="rmm-table">
                    <thead>
                        <tr>
                            <th style="width:220px;">Страница / Запись</th>
                            <th style="width:90px;">Тип</th>
                            <th style="width:110px;">Lang</th>
                            <th>Canonical URL</th>
                            <th style="width:110px;text-align:center;">Hreflang</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($posts as $p):
                            $pid       = $p->ID;
                            $canonical = get_post_meta($pid, '_regional_canonical', true);
                            $lang      = get_post_meta($pid, '_regional_lang', true);
                            $hreflang  = get_post_meta($pid, '_regional_hreflang', true);
                            if (!is_array($hreflang)) $hreflang = [];
                            $type_label = $p->post_type === 'page' ? 'Страница' : 'Запись';
                            $path       = parse_url(get_permalink($pid), PHP_URL_PATH);
                        ?>
                        <tr class="rmm-row" data-post-id="<?php echo $pid; ?>" data-search="<?php echo esc_attr(mb_strtolower($p->post_title . ' ' . $path)); ?>">
                            <td>
                                <a href="<?php echo esc_url(get_edit_post_link($pid)); ?>" target="_blank"><?php echo esc_html($p->post_title ?: '(без названия)'); ?></a>
                                <br><small style="color:#666;"><?php echo esc_html($path); ?></small>
                            </td>
                            <td><?php echo $type_label; ?></td>
                            <td>
                                <input type="text" class="rmm-lang" value="<?php echo esc_attr($lang); ?>" placeholder="ru-RU" style="width:100%;" />
                            </td>
                            <td>
                                <input type="url" class="rmm-canonical" value="<?php echo esc_url($canonical); ?>" placeholder="https://domain.com/page/" style="width:100%;" />
                            </td>
                            <td style="text-align:center;">
                                <button type="button" class="button rmm-toggle-hreflang" data-post-id="<?php echo $pid; ?>">
                                    <?php echo count($hreflang) > 0 ? count($hreflang) . ' ред.' : '+ Добавить'; ?>
                                </button>
                            </td>
                        </tr>
                        <tr class="rmm-hreflang-row" id="rmm-hl-<?php echo $pid; ?>" style="display:none;">
                            <td colspan="5" style="padding:14px 20px;background:#f6f7f7;">
                                <strong>Hreflang: <?php echo esc_html($p->post_title); ?></strong>
                                <div class="rmm-hl-container" id="rmm-hl-container-<?php echo $pid; ?>" style="margin:8px 0;">
                                    <?php foreach ($hreflang as $h): ?>
                                    <div class="rmm-hl-entry" style="display:flex;gap:8px;margin:4px 0;align-items:center;">
                                        <input type="text" class="rmm-hl-lang" value="<?php echo esc_attr($h['lang'] ?? ''); ?>" placeholder="fr-BE" style="width:120px;" />
                                        <input type="url"  class="rmm-hl-url"  value="<?php echo esc_url($h['url'] ?? ''); ?>"  placeholder="https://site.com/fr/" style="flex:1;" />
                                        <button type="button" class="button rmm-remove-hl">Удалить</button>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <button type="button" class="button rmm-add-hl" data-post-id="<?php echo $pid; ?>">+ Добавить строку</button>
                                <button type="button" class="button button-primary rmm-save-hl" data-post-id="<?php echo $pid; ?>" style="margin-left:6px;">Сохранить hreflang</button>
                                <span class="rmm-hl-status" style="margin-left:8px;font-weight:500;"></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            const nonce = '<?php echo esc_js($nonce); ?>';

            $('#rmm-filter').on('input', function() {
                const val = $(this).val().toLowerCase();
                $('.rmm-row').each(function() {
                    const match = !val || $(this).data('search').includes(val);
                    $(this).toggle(match);
                    if (!match) {
                        const pid = $(this).data('post-id');
                        $('#rmm-hl-' + pid).hide();
                    }
                });
            });

            $(document).on('click', '.rmm-toggle-hreflang', function() {
                $('#rmm-hl-' + $(this).data('post-id')).toggle();
            });

            $(document).on('click', '.rmm-add-hl', function() {
                $('#rmm-hl-container-' + $(this).data('post-id')).append(
                    '<div class="rmm-hl-entry" style="display:flex;gap:8px;margin:4px 0;align-items:center;">' +
                    '<input type="text" class="rmm-hl-lang" placeholder="fr-BE" style="width:120px;" />' +
                    '<input type="url" class="rmm-hl-url" placeholder="https://site.com/fr/" style="flex:1;" />' +
                    '<button type="button" class="button rmm-remove-hl">Удалить</button>' +
                    '</div>'
                );
            });

            $(document).on('click', '.rmm-remove-hl', function() {
                $(this).closest('.rmm-hl-entry').remove();
            });

            function collectHreflang(pid) {
                const entries = [];
                $('#rmm-hl-container-' + pid + ' .rmm-hl-entry').each(function() {
                    const lang = $(this).find('.rmm-hl-lang').val().trim();
                    const url  = $(this).find('.rmm-hl-url').val().trim();
                    if (lang && url) entries.push({lang, url});
                });
                return entries;
            }

            $(document).on('click', '.rmm-save-hl', function() {
                const btn   = $(this);
                const pid   = btn.data('post-id');
                const hl    = collectHreflang(pid);
                const status = btn.siblings('.rmm-hl-status');

                btn.prop('disabled', true).text('Сохранение...');
                $.post(ajaxurl, {
                    action: 'rmm_bulk_save',
                    nonce,
                    items: JSON.stringify([{
                        post_id:   pid,
                        lang:      $('.rmm-row[data-post-id="' + pid + '"] .rmm-lang').val(),
                        canonical: $('.rmm-row[data-post-id="' + pid + '"] .rmm-canonical').val(),
                        hreflang:  hl,
                    }])
                }, function(res) {
                    btn.prop('disabled', false).text('Сохранить hreflang');
                    if (res.success) {
                        $('.rmm-toggle-hreflang[data-post-id="' + pid + '"]').text(hl.length > 0 ? hl.length + ' ред.' : '+ Добавить');
                        flash(status, 'Сохранено', 'green');
                    } else {
                        flash(status, 'Ошибка: ' + (res.data || '?'), 'red');
                    }
                });
            });

            $('#rmm-save-all').on('click', function() {
                const btn   = $(this);
                const items = [];

                $('.rmm-row:visible').each(function() {
                    const pid = $(this).data('post-id');
                    items.push({
                        post_id:   pid,
                        lang:      $(this).find('.rmm-lang').val().trim(),
                        canonical: $(this).find('.rmm-canonical').val().trim(),
                        hreflang:  collectHreflang(pid),
                    });
                });

                if (!items.length) return;

                btn.prop('disabled', true).text('Сохранение...');
                $.post(ajaxurl, {
                    action: 'rmm_bulk_save',
                    nonce,
                    items: JSON.stringify(items)
                }, function(res) {
                    btn.prop('disabled', false).text('Сохранить всё');
                    if (res.success) {
                        flash('#rmm-save-status', 'Сохранено ' + res.data.count + ' записей', 'green');
                    } else {
                        flash('#rmm-save-status', 'Ошибка: ' + (res.data || '?'), 'red');
                    }
                });
            });

            $('#rmm-auto-replace').on('click', function() {
                const btn       = $(this);
                const oldDomain = $('#rmm-old-domain').val().trim().replace(/\/$/, '');
                const newDomain = $('#rmm-new-domain').val().trim().replace(/\/$/, '');
                const generate  = $('#rmm-generate-missing').is(':checked') ? 1 : 0;

                if (!newDomain) {
                    flash('#rmm-replace-status', 'Укажите новый домен', 'red');
                    return;
                }
                if (!oldDomain && !generate) {
                    flash('#rmm-replace-status', 'Укажите старый домен или включите «Сгенерировать»', 'red');
                    return;
                }

                btn.prop('disabled', true).text('Применение...');
                $.post(ajaxurl, {
                    action:            'rmm_domain_replace',
                    nonce,
                    old_domain:        oldDomain,
                    new_domain:        newDomain,
                    replace_hreflang:  $('#rmm-replace-hreflang-url').is(':checked') ? 1 : 0,
                    generate_missing:  generate,
                }, function(res) {
                    btn.prop('disabled', false).text('Применить');
                    if (res.success) {
                        flash('#rmm-replace-status',
                            'Заменено: ' + res.data.replaced + ', сгенерировано: ' + res.data.generated + '. Перезагрузите страницу.',
                            'green');
                    } else {
                        flash('#rmm-replace-status', 'Ошибка: ' + (res.data || '?'), 'red');
                    }
                });
            });

            $('#rmm-lang-replace').on('click', function() {
                const btn     = $(this);
                const oldLang = $('#rmm-old-lang').val().trim();
                const newLang = $('#rmm-new-lang').val().trim();

                if (!oldLang || !newLang) {
                    flash('#rmm-lang-status', 'Укажите оба кода', 'red');
                    return;
                }

                btn.prop('disabled', true).text('Применение...');
                $.post(ajaxurl, {
                    action:               'rmm_lang_replace',
                    nonce,
                    old_lang:             oldLang,
                    new_lang:             newLang,
                    replace_html_lang:    $('#rmm-replace-html-lang').is(':checked') ? 1 : 0,
                    replace_hreflang_code: $('#rmm-replace-hreflang-code').is(':checked') ? 1 : 0,
                }, function(res) {
                    btn.prop('disabled', false).text('Применить');
                    if (res.success) {
                        flash('#rmm-lang-status', 'Обновлено: ' + res.data.count + ' значений. Перезагрузите страницу.', 'green');
                    } else {
                        flash('#rmm-lang-status', 'Ошибка: ' + (res.data || '?'), 'red');
                    }
                });
            });

            function flash(selector, text, color) {
                const el = $(selector).css('color', color).text(text);
                setTimeout(() => el.text('').css('color', ''), 6000);
            }
        });
        </script>
        <?php
    }

    public function ajaxBulkSave(): void
    {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', self::NONCE_ACTION) || !current_user_can('manage_options')) {
            wp_send_json_error('Ошибка безопасности');
        }

        $items = json_decode(stripslashes($_POST['items'] ?? '[]'), true);
        if (!is_array($items)) {
            wp_send_json_error('Неверный формат данных');
        }

        $count = 0;
        foreach ($items as $item) {
            $post_id = (int) ($item['post_id'] ?? 0);
            if (!$post_id || !get_post($post_id) || !current_user_can('edit_post', $post_id)) {
                continue;
            }

            $lang = sanitize_text_field($item['lang'] ?? '');
            if ($lang) {
                update_post_meta($post_id, '_regional_lang', $lang);
            } else {
                delete_post_meta($post_id, '_regional_lang');
            }

            $canonical = trim($item['canonical'] ?? '');
            if ($canonical && filter_var($canonical, FILTER_VALIDATE_URL)) {
                update_post_meta($post_id, '_regional_canonical', esc_url_raw($canonical));
            } else {
                delete_post_meta($post_id, '_regional_canonical');
            }

            $hreflang = [];
            if (is_array($item['hreflang'] ?? null)) {
                foreach ($item['hreflang'] as $h) {
                    $hl = sanitize_text_field($h['lang'] ?? '');
                    $hu = esc_url_raw($h['url'] ?? '');
                    if ($hl && $hu) {
                        $hreflang[] = ['lang' => $hl, 'url' => $hu];
                    }
                }
            }
            if (!empty($hreflang)) {
                update_post_meta($post_id, '_regional_hreflang', $hreflang);
            } else {
                delete_post_meta($post_id, '_regional_hreflang');
            }

            $count++;
        }

        wp_send_json_success(['count' => $count]);
    }

    public function ajaxDomainReplace(): void
    {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', self::NONCE_ACTION) || !current_user_can('manage_options')) {
            wp_send_json_error('Ошибка безопасности');
        }

        $old_domain       = rtrim(wp_strip_all_tags($_POST['old_domain'] ?? ''), '/');
        $new_domain       = rtrim(wp_strip_all_tags($_POST['new_domain'] ?? ''), '/');
        $replace_hreflang = !empty($_POST['replace_hreflang']);
        $generate_missing = !empty($_POST['generate_missing']);

        if (!$new_domain) {
            wp_send_json_error('Укажите новый домен');
        }

        $posts = get_posts([
            'post_type'   => self::SUPPORTED_TYPES,
            'post_status' => 'publish',
            'numberposts' => -1,
        ]);

        $replaced  = 0;
        $generated = 0;

        foreach ($posts as $post) {
            $pid       = $post->ID;
            $canonical = get_post_meta($pid, '_regional_canonical', true);

            if ($canonical) {
                if ($old_domain && stripos($canonical, $old_domain) !== false) {
                    update_post_meta($pid, '_regional_canonical', esc_url_raw(
                        str_ireplace($old_domain, $new_domain, $canonical)
                    ));
                    $replaced++;
                }
            } elseif ($generate_missing) {
                $permalink = get_permalink($pid);
                if ($permalink) {
                    $path         = parse_url($permalink, PHP_URL_PATH);
                    $query        = parse_url($permalink, PHP_URL_QUERY);
                    $new_canonical = rtrim($new_domain, '/') . $path . ($query ? '?' . $query : '');
                    update_post_meta($pid, '_regional_canonical', esc_url_raw($new_canonical));
                    $generated++;
                }
            }

            if ($replace_hreflang && $old_domain) {
                $hreflang = get_post_meta($pid, '_regional_hreflang', true);
                if (is_array($hreflang) && !empty($hreflang)) {
                    $updated = false;
                    foreach ($hreflang as &$h) {
                        if (!empty($h['url']) && stripos($h['url'], $old_domain) !== false) {
                            $h['url'] = esc_url_raw(str_ireplace($old_domain, $new_domain, $h['url']));
                            $updated  = true;
                            $replaced++;
                        }
                    }
                    unset($h);
                    if ($updated) {
                        update_post_meta($pid, '_regional_hreflang', $hreflang);
                    }
                }
            }
        }

        wp_send_json_success(['replaced' => $replaced, 'generated' => $generated]);
    }

    /**
     * BCP47-style match: trimmed, case-insensitive (e.g. DB ru-RU vs input ru-ru).
     */
    private function langCodesMatch($stored, string $needle): bool
    {
        if (!is_string($stored)) {
            return false;
        }
        $stored = trim($stored);
        $needle  = trim($needle);
        if ($stored === '' || $needle === '') {
            return false;
        }
        return strcasecmp($stored, $needle) === 0;
    }

    public function ajaxLangReplace(): void
    {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', self::NONCE_ACTION) || !current_user_can('manage_options')) {
            wp_send_json_error('Ошибка безопасности');
        }

        $old_lang              = sanitize_text_field(wp_unslash($_POST['old_lang'] ?? ''));
        $new_lang              = sanitize_text_field(wp_unslash($_POST['new_lang'] ?? ''));
        $replace_html_lang     = !empty($_POST['replace_html_lang']);
        $replace_hreflang_code = !empty($_POST['replace_hreflang_code']);

        if ($old_lang === '' || $new_lang === '') {
            wp_send_json_error('Укажите оба языковых кода');
        }

        if (0 === strcasecmp($old_lang, $new_lang)) {
            wp_send_json_error('Старый и новый код совпадают');
        }

        $posts = get_posts([
            'post_type'   => self::SUPPORTED_TYPES,
            'post_status' => 'publish',
            'numberposts' => -1,
        ]);

        $count = 0;
        foreach ($posts as $post) {
            $pid = $post->ID;

            if ($replace_html_lang) {
                $lang = get_post_meta($pid, '_regional_lang', true);
                if ($this->langCodesMatch($lang, $old_lang)) {
                    update_post_meta($pid, '_regional_lang', $new_lang);
                    $count++;
                }
            }

            if ($replace_hreflang_code) {
                $hreflang = get_post_meta($pid, '_regional_hreflang', true);
                if (is_array($hreflang) && !empty($hreflang)) {
                    $updated = false;
                    foreach ($hreflang as &$h) {
                        if (!is_array($h)) {
                            continue;
                        }
                        $hl = $h['lang'] ?? '';
                        if ($this->langCodesMatch(is_string($hl) ? $hl : '', $old_lang)) {
                            $h['lang'] = $new_lang;
                            $updated   = true;
                            $count++;
                        }
                    }
                    unset($h);
                    if ($updated) {
                        update_post_meta($pid, '_regional_hreflang', $hreflang);
                    }
                }
            }
        }

        wp_send_json_success(['count' => $count]);
    }
}
