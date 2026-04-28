<?php
if (!defined('ABSPATH')) {
    exit;
}

class RMMBulkManager
{
    const NONCE_ACTION    = 'rmm_bulk_manager';
    const SUPPORTED_TYPES = ['page', 'post'];
    const LOG_OPTION      = 'rmm_action_log';
    const LOG_MAX         = 50;

    public function __construct()
    {
        add_action('admin_menu',  [$this, 'addAdminPage']);
        add_action('wp_ajax_rmm_bulk_save',              [$this, 'ajaxBulkSave']);
        add_action('wp_ajax_rmm_domain_replace',         [$this, 'ajaxDomainReplace']);
        add_action('wp_ajax_rmm_lang_replace',           [$this, 'ajaxLangReplace']);
        add_action('wp_ajax_rmm_bulk_add_hreflang',      [$this, 'ajaxBulkAddHreflang']);
        add_action('wp_ajax_rmm_selective_add_hreflang', [$this, 'ajaxSelectiveAddHreflang']);
        add_action('wp_ajax_rmm_bulk_delete_hreflang',   [$this, 'ajaxBulkDeleteHreflang']);
        add_action('wp_ajax_rmm_clear_log',              [$this, 'ajaxClearLog']);
        add_action('admin_enqueue_scripts',              [$this, 'enqueueScripts']);
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

    private function buildHreflangUrl(string $pattern, int $post_id): string
    {
        if (strpos($pattern, '{path}') === false) {
            return $pattern;
        }
        $permalink = get_permalink($post_id);
        if (!$permalink) {
            return '';
        }
        $path  = parse_url($permalink, PHP_URL_PATH) ?? '/';
        $query = parse_url($permalink, PHP_URL_QUERY);
        return str_replace('{path}', $path . ($query ? '?' . $query : ''), $pattern);
    }

    private function applyHreflangToPost(int $pid, array $entries, string $mode): int
    {
        $existing = get_post_meta($pid, '_regional_hreflang', true);
        if (!is_array($existing)) {
            $existing = [];
        }

        $new = [];
        foreach ($entries as $e) {
            $lang = sanitize_text_field($e['lang'] ?? '');
            $url  = esc_url_raw($this->buildHreflangUrl(sanitize_text_field($e['url'] ?? ''), $pid));
            if ($lang !== '' && $url !== '') {
                $new[] = ['lang' => $lang, 'url' => $url];
            }
        }

        if (empty($new)) {
            return 0;
        }

        if ($mode === 'replace') {
            $merged = $new;
        } else {
            $merged = $existing;
            foreach ($new as $n) {
                $dup = false;
                foreach ($merged as $m) {
                    if (
                        is_array($m) &&
                        strcasecmp($m['lang'] ?? '', $n['lang']) === 0 &&
                        ($m['url'] ?? '') === $n['url']
                    ) {
                        $dup = true;
                        break;
                    }
                }
                if (!$dup) {
                    $merged[] = $n;
                }
            }
        }

        if (!empty($merged)) {
            update_post_meta($pid, '_regional_hreflang', $merged);
        } else {
            delete_post_meta($pid, '_regional_hreflang');
        }

        return count($new);
    }

    private function addLogEntry(array $entry): void
    {
        $log = get_option(self::LOG_OPTION, []);
        if (!is_array($log)) {
            $log = [];
        }
        array_unshift($log, $entry);
        if (count($log) > self::LOG_MAX) {
            $log = array_slice($log, 0, self::LOG_MAX);
        }
        update_option(self::LOG_OPTION, $log, false);
    }

    private function getLog(): array
    {
        $log = get_option(self::LOG_OPTION, []);
        return is_array($log) ? $log : [];
    }

    private function renderLog(): void
    {
        $log = $this->getLog();
        if (empty($log)) {
            echo '<p style="color:#666;font-style:italic;margin:8px 0 0;">Операции ещё не выполнялись.</p>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed" style="margin-top:10px;">';
        echo '<thead><tr>';
        echo '<th style="width:155px;">Время</th>';
        echo '<th style="width:190px;">Операция</th>';
        echo '<th>Детали</th>';
        echo '<th style="width:75px;text-align:center;">Стр.</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        $alt = false;
        foreach ($log as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $bg    = $alt ? 'background:#f6f7f7;' : '';
            $alt   = !$alt;
            $time  = date_i18n(get_option('date_format') . ' H:i:s', $entry['time'] ?? 0);
            $label = esc_html($entry['label'] ?? $entry['action'] ?? '—');
            $count = (int) ($entry['count'] ?? 0);

            $det = '';
            if (!empty($entry['entries']) && is_array($entry['entries'])) {
                $langs = array_filter(array_map(
                    fn($e) => is_array($e) && !empty($e['lang']) ? esc_html($e['lang']) : '',
                    $entry['entries']
                ));
                if ($langs) {
                    $det .= 'Коды: <strong>' . implode(', ', $langs) . '</strong>';
                }
                if (!empty($entry['mode'])) {
                    $det .= ' <em style="color:#666;">(' . esc_html($entry['mode'] === 'replace' ? 'заменить' : 'дополнить') . ')</em>';
                }
            }
            if (!empty($entry['codes']) && is_array($entry['codes'])) {
                $det .= 'Удалены: <strong>' . esc_html(implode(', ', $entry['codes'])) . '</strong>';
            }

            $items = '';
            if (!empty($entry['details']) && is_array($entry['details'])) {
                $slice = array_slice($entry['details'], 0, 30);
                foreach ($slice as $d) {
                    if (!is_array($d)) {
                        continue;
                    }
                    $items .= '<li>' . esc_html($d['title'] ?? '') . ' <span style="color:#888;">(ID ' . (int) ($d['id'] ?? 0) . ')</span></li>';
                }
                $more = count($entry['details']) - 30;
                if ($more > 0) {
                    $items .= '<li style="color:#888;">… ещё ' . $more . '</li>';
                }
            }

            echo '<tr style="' . $bg . '">';
            echo '<td style="vertical-align:top;white-space:nowrap;font-size:12px;">' . esc_html($time) . '</td>';
            echo '<td style="vertical-align:top;">' . $label . '</td>';
            echo '<td style="vertical-align:top;">' . $det;
            if ($items) {
                echo '<details style="margin-top:4px;"><summary style="cursor:pointer;color:#0073aa;user-select:none;">Показать страницы</summary>'
                    . '<ul style="margin:4px 0 0 16px;padding:0;max-height:160px;overflow-y:auto;">' . $items . '</ul></details>';
            }
            echo '</td>';
            echo '<td style="vertical-align:top;text-align:center;">' . $count . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }

        $posts = $this->getAllPosts();
        $nonce = wp_create_nonce(self::NONCE_ACTION);
        $card  = 'background:#fff;border:1px solid #c3c4c7;padding:20px 24px;border-radius:4px;';
        $h2s   = 'margin-top:0;';
        $pdesc = 'color:#50575e;margin-top:0;';
        ?>
        <div class="wrap">
            <h1>RMM — Массовое управление мета</h1>

            <!-- ── Замены ── -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin:20px 0;">

                <div style="<?php echo $card; ?>">
                    <h2 style="<?php echo $h2s; ?>">Автозамена домена</h2>
                    <p style="<?php echo $pdesc; ?>">Заменяет или генерирует canonical / hreflang URL по всему сайту.</p>
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

                <div style="<?php echo $card; ?>">
                    <h2 style="<?php echo $h2s; ?>">Автозамена языкового кода</h2>
                    <p style="<?php echo $pdesc; ?>">Заменяет lang-код в атрибуте HTML и/или в кодах hreflang по всему сайту.</p>
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

            <!-- ── Добавление hreflang ── -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin:20px 0;">

                <div style="<?php echo $card; ?>">
                    <h2 style="<?php echo $h2s; ?>">Добавить hreflang на все страницы</h2>
                    <p style="<?php echo $pdesc; ?>">Добавляет записи ко всем опубликованным страницам и записям. В URL можно использовать <code>{path}</code> — заменяется на путь каждой страницы, например <code>https://fr.site.com{path}</code>.</p>
                    <div id="rmm-bulk-all-entries" style="margin-bottom:8px;">
                        <div class="rmm-hl-input-row" style="display:flex;gap:8px;margin:4px 0;align-items:center;">
                            <input type="text" class="rmm-hl-input-lang" placeholder="fr-FR" style="width:110px;" />
                            <input type="text" class="rmm-hl-input-url"  placeholder="https://fr.site.com{path}" style="flex:1;" />
                            <button type="button" class="button rmm-remove-hl-input-row" title="Удалить строку">×</button>
                        </div>
                    </div>
                    <button type="button" class="button rmm-add-hl-row" data-target="rmm-bulk-all-entries" style="margin-bottom:12px;">+ Добавить строку</button>
                    <div style="margin-bottom:12px;">
                        <label style="margin-right:16px;"><input type="radio" name="rmm-bulk-all-mode" value="merge" checked> Дополнить существующие</label>
                        <label><input type="radio" name="rmm-bulk-all-mode" value="replace"> Заменить все hreflang</label>
                    </div>
                    <button class="button button-primary" id="rmm-bulk-add-all">Применить ко всем страницам</button>
                    <span id="rmm-bulk-all-status" style="font-weight:500;display:block;margin-top:6px;min-height:1.4em;"></span>
                </div>

                <div style="<?php echo $card; ?>">
                    <h2 style="<?php echo $h2s; ?>">Точечное добавление hreflang</h2>
                    <p style="<?php echo $pdesc; ?>">Добавляет hreflang-записи только на выбранные страницы. Поддерживается <code>{path}</code>.</p>
                    <div id="rmm-sel-entries" style="margin-bottom:8px;">
                        <div class="rmm-hl-input-row" style="display:flex;gap:8px;margin:4px 0;align-items:center;">
                            <input type="text" class="rmm-hl-input-lang" placeholder="fr-FR" style="width:110px;" />
                            <input type="text" class="rmm-hl-input-url"  placeholder="https://fr.site.com{path}" style="flex:1;" />
                            <button type="button" class="button rmm-remove-hl-input-row" title="Удалить строку">×</button>
                        </div>
                    </div>
                    <button type="button" class="button rmm-add-hl-row" data-target="rmm-sel-entries" style="margin-bottom:12px;">+ Добавить строку</button>
                    <div style="margin-bottom:12px;">
                        <label style="margin-right:16px;"><input type="radio" name="rmm-sel-mode" value="merge" checked> Дополнить существующие</label>
                        <label><input type="radio" name="rmm-sel-mode" value="replace"> Заменить все hreflang</label>
                    </div>
                    <div style="font-weight:600;margin-bottom:6px;">Выберите страницы:</div>
                    <input type="text" id="rmm-sel-search" placeholder="Фильтр по названию или пути..." style="width:100%;margin-bottom:6px;" />
                    <div id="rmm-sel-page-list" style="max-height:180px;overflow-y:auto;border:1px solid #ddd;padding:8px 10px;background:#f9f9f9;border-radius:3px;">
                        <?php foreach ($posts as $p):
                            $spath = esc_attr((string) parse_url(get_permalink($p->ID), PHP_URL_PATH));
                        ?>
                        <label class="rmm-sel-label" style="display:block;padding:3px 0;cursor:pointer;" data-search="<?php echo esc_attr(mb_strtolower($p->post_title . ' ' . $spath)); ?>">
                            <input type="checkbox" class="rmm-sel-cb" value="<?php echo $p->ID; ?>" style="margin-right:5px;" />
                            <?php echo esc_html($p->post_title ?: '(без названия)'); ?>
                            <small style="color:#888;"><?php echo $spath; ?></small>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div style="margin:6px 0 12px;">
                        <button type="button" class="button" id="rmm-sel-all">Выбрать все</button>
                        <button type="button" class="button" id="rmm-sel-none" style="margin-left:4px;">Снять все</button>
                        <span id="rmm-sel-count" style="margin-left:8px;color:#666;font-size:12px;"></span>
                    </div>
                    <button class="button button-primary" id="rmm-sel-apply">Применить к выбранным</button>
                    <span id="rmm-sel-status" style="font-weight:500;display:block;margin-top:6px;min-height:1.4em;"></span>
                </div>

            </div>

            <!-- ── Удаление hreflang ── -->
            <div style="<?php echo $card; ?>margin:20px 0;">
                <h2 style="<?php echo $h2s; ?>">Массовое удаление hreflang по коду языка</h2>
                <p style="<?php echo $pdesc; ?>">Введите коды языков (по одному на строке или через запятую) — эти hreflang-записи будут удалены со всех страниц и записей сайта.</p>
                <textarea id="rmm-del-codes" rows="4" style="width:100%;max-width:420px;font-family:monospace;resize:vertical;" placeholder="fr-FR&#10;en-US&#10;de-DE"></textarea>
                <div style="margin-top:10px;">
                    <button class="button button-primary" id="rmm-bulk-del-hl">Удалить из всех страниц</button>
                    <span id="rmm-del-status" style="margin-left:10px;font-weight:500;"></span>
                </div>
            </div>

            <!-- ── Ручное редактирование ── -->
            <div style="<?php echo $card; ?>margin:20px 0;">
                <h2 style="<?php echo $h2s; ?>">Ручное редактирование</h2>
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

            <!-- ── Журнал ── -->
            <div style="<?php echo $card; ?>margin:20px 0;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                    <h2 style="<?php echo $h2s; ?>">Журнал операций</h2>
                    <button class="button" id="rmm-clear-log">Очистить журнал</button>
                </div>
                <p style="<?php echo $pdesc; ?>margin:0 0 4px;">Последние <?php echo self::LOG_MAX; ?> операций из секций добавления / удаления hreflang.</p>
                <div id="rmm-log-wrap">
                    <?php $this->renderLog(); ?>
                </div>
            </div>

        </div><!-- .wrap -->

        <script>
        jQuery(document).ready(function($) {
            const nonce = '<?php echo esc_js($nonce); ?>';

            function flash(el, text, color) {
                const $el = (typeof el === 'string') ? $(el) : el;
                $el.css('color', color || '#000').text(text);
                setTimeout(() => $el.text('').css('color', ''), 7000);
            }

            function collectHlInputRows(containerId) {
                const entries = [];
                $('#' + containerId + ' .rmm-hl-input-row').each(function() {
                    const lang = $(this).find('.rmm-hl-input-lang').val().trim();
                    const url  = $(this).find('.rmm-hl-input-url').val().trim();
                    if (lang && url) entries.push({lang, url});
                });
                return entries;
            }

            $(document).on('click', '.rmm-add-hl-row', function() {
                const tgt = $(this).data('target');
                $('#' + tgt).append(
                    '<div class="rmm-hl-input-row" style="display:flex;gap:8px;margin:4px 0;align-items:center;">' +
                    '<input type="text" class="rmm-hl-input-lang" placeholder="fr-FR" style="width:110px;" />' +
                    '<input type="text" class="rmm-hl-input-url"  placeholder="https://fr.site.com{path}" style="flex:1;" />' +
                    '<button type="button" class="button rmm-remove-hl-input-row" title="Удалить строку">×</button>' +
                    '</div>'
                );
            });

            $(document).on('click', '.rmm-remove-hl-input-row', function() {
                const $parent = $(this).closest('.rmm-hl-input-row').parent();
                if ($parent.find('.rmm-hl-input-row').length > 1) {
                    $(this).closest('.rmm-hl-input-row').remove();
                }
            });

            function prependLogRow(label, langs, mode, count) {
                const now = new Date().toLocaleString('ru-RU');
                let det = '';
                if (langs) {
                    det += 'Коды: <strong>' + $('<span>').text(langs).html() + '</strong>';
                }
                if (mode) {
                    det += ' <em>(' + (mode === 'replace' ? 'заменить' : 'дополнить') + ')</em>';
                }
                const row = '<tr>' +
                    '<td style="font-size:12px;white-space:nowrap;">' + now + '</td>' +
                    '<td>' + $('<span>').text(label).html() + '</td>' +
                    '<td>' + det + '</td>' +
                    '<td style="text-align:center;">' + count + '</td>' +
                    '</tr>';
                const $tbody = $('#rmm-log-wrap table tbody');
                if ($tbody.length) {
                    $tbody.prepend(row);
                } else {
                    $('#rmm-log-wrap').html(
                        '<table class="wp-list-table widefat fixed" style="margin-top:10px;">' +
                        '<thead><tr><th style="width:155px;">Время</th><th style="width:190px;">Операция</th><th>Детали</th><th style="width:75px;text-align:center;">Стр.</th></tr></thead>' +
                        '<tbody>' + row + '</tbody></table>'
                    );
                }
            }

            // ── Ручное редактирование ──────────────────────────────────────────

            $('#rmm-filter').on('input', function() {
                const val = $(this).val().toLowerCase();
                $('.rmm-row').each(function() {
                    const match = !val || String($(this).data('search') || '').includes(val);
                    $(this).toggle(match);
                    if (!match) $('#rmm-hl-' + $(this).data('post-id')).hide();
                });
            });

            $(document).on('click', '.rmm-toggle-hreflang', function() {
                $('#rmm-hl-' + $(this).data('post-id')).toggle();
            });

            $(document).on('click', '.rmm-add-hl', function() {
                $('#rmm-hl-container-' + $(this).data('post-id')).append(
                    '<div class="rmm-hl-entry" style="display:flex;gap:8px;margin:4px 0;align-items:center;">' +
                    '<input type="text" class="rmm-hl-lang" placeholder="fr-BE" style="width:120px;" />' +
                    '<input type="url"  class="rmm-hl-url"  placeholder="https://site.com/fr/" style="flex:1;" />' +
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
                const btn    = $(this);
                const pid    = btn.data('post-id');
                const hl     = collectHreflang(pid);
                const $stat  = btn.siblings('.rmm-hl-status');
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
                        flash($stat, 'Сохранено', 'green');
                    } else {
                        flash($stat, 'Ошибка: ' + (res.data || '?'), 'red');
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
                $.post(ajaxurl, {action: 'rmm_bulk_save', nonce, items: JSON.stringify(items)}, function(res) {
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
                if (!newDomain) { flash('#rmm-replace-status', 'Укажите новый домен', 'red'); return; }
                if (!oldDomain && !generate) { flash('#rmm-replace-status', 'Укажите старый домен или включите «Сгенерировать»', 'red'); return; }
                btn.prop('disabled', true).text('Применение...');
                $.post(ajaxurl, {
                    action: 'rmm_domain_replace', nonce,
                    old_domain: oldDomain, new_domain: newDomain,
                    replace_hreflang: $('#rmm-replace-hreflang-url').is(':checked') ? 1 : 0,
                    generate_missing: generate,
                }, function(res) {
                    btn.prop('disabled', false).text('Применить');
                    if (res.success) {
                        flash('#rmm-replace-status', 'Заменено: ' + res.data.replaced + ', сгенерировано: ' + res.data.generated + '. Перезагрузите страницу.', 'green');
                    } else {
                        flash('#rmm-replace-status', 'Ошибка: ' + (res.data || '?'), 'red');
                    }
                });
            });

            $('#rmm-lang-replace').on('click', function() {
                const btn     = $(this);
                const oldLang = $('#rmm-old-lang').val().trim();
                const newLang = $('#rmm-new-lang').val().trim();
                if (!oldLang || !newLang) { flash('#rmm-lang-status', 'Укажите оба кода', 'red'); return; }
                btn.prop('disabled', true).text('Применение...');
                $.post(ajaxurl, {
                    action: 'rmm_lang_replace', nonce,
                    old_lang: oldLang, new_lang: newLang,
                    replace_html_lang:     $('#rmm-replace-html-lang').is(':checked') ? 1 : 0,
                    replace_hreflang_code: $('#rmm-replace-hreflang-code').is(':checked') ? 1 : 0,
                }, function(res) {
                    btn.prop('disabled', false).text('Применить');
                    if (res.success) {
                        const hl = typeof res.data.hreflang !== 'undefined' ? res.data.hreflang : 0;
                        const hlHtml = typeof res.data.html_lang !== 'undefined' ? res.data.html_lang : 0;
                        flash('#rmm-lang-status',
                            'Всего: ' + res.data.count + ' (HTML lang: ' + hlHtml + ', hreflang: ' + hl + '). Перезагрузите страницу.', 'green');
                    } else {
                        flash('#rmm-lang-status', 'Ошибка: ' + (res.data || '?'), 'red');
                    }
                });
            });

            // ── Добавить на все страницы ───────────────────────────────────────

            $('#rmm-bulk-add-all').on('click', function() {
                const btn     = $(this);
                const entries = collectHlInputRows('rmm-bulk-all-entries');
                const mode    = $('input[name="rmm-bulk-all-mode"]:checked').val();
                if (!entries.length) { flash('#rmm-bulk-all-status', 'Добавьте хотя бы одну hreflang-запись', 'red'); return; }
                btn.prop('disabled', true).text('Применение...');
                $.post(ajaxurl, {
                    action: 'rmm_bulk_add_hreflang', nonce,
                    entries: JSON.stringify(entries), mode,
                }, function(res) {
                    btn.prop('disabled', false).text('Применить ко всем страницам');
                    if (res.success) {
                        flash('#rmm-bulk-all-status', 'Добавлено на ' + res.data.count + ' страниц', 'green');
                        prependLogRow('Добавить на все', entries.map(e => e.lang).join(', '), mode, res.data.count);
                    } else {
                        flash('#rmm-bulk-all-status', 'Ошибка: ' + (res.data || '?'), 'red');
                    }
                });
            });

            // ── Точечное добавление ────────────────────────────────────────────

            $('#rmm-sel-search').on('input', function() {
                const val = $(this).val().toLowerCase();
                $('.rmm-sel-label').each(function() {
                    $(this).toggle(!val || String($(this).data('search') || '').includes(val));
                });
            });

            $('#rmm-sel-all').on('click', function() {
                $('.rmm-sel-label:visible .rmm-sel-cb').prop('checked', true);
                updateSelCount();
            });

            $('#rmm-sel-none').on('click', function() {
                $('.rmm-sel-cb').prop('checked', false);
                updateSelCount();
            });

            $(document).on('change', '.rmm-sel-cb', updateSelCount);

            function updateSelCount() {
                const n = $('.rmm-sel-cb:checked').length;
                $('#rmm-sel-count').text(n ? n + ' выбрано' : '');
            }

            $('#rmm-sel-apply').on('click', function() {
                const btn      = $(this);
                const entries  = collectHlInputRows('rmm-sel-entries');
                const post_ids = [];
                $('.rmm-sel-cb:checked').each(function() { post_ids.push(parseInt($(this).val(), 10)); });
                const mode = $('input[name="rmm-sel-mode"]:checked').val();
                if (!entries.length) { flash('#rmm-sel-status', 'Добавьте хотя бы одну hreflang-запись', 'red'); return; }
                if (!post_ids.length) { flash('#rmm-sel-status', 'Выберите хотя бы одну страницу', 'red'); return; }
                btn.prop('disabled', true).text('Применение...');
                $.post(ajaxurl, {
                    action: 'rmm_selective_add_hreflang', nonce,
                    entries: JSON.stringify(entries), post_ids: JSON.stringify(post_ids), mode,
                }, function(res) {
                    btn.prop('disabled', false).text('Применить к выбранным');
                    if (res.success) {
                        flash('#rmm-sel-status', 'Добавлено на ' + res.data.count + ' страниц', 'green');
                        prependLogRow('Точечное добавление', entries.map(e => e.lang).join(', '), mode, res.data.count);
                    } else {
                        flash('#rmm-sel-status', 'Ошибка: ' + (res.data || '?'), 'red');
                    }
                });
            });

            // ── Удаление hreflang ──────────────────────────────────────────────

            $('#rmm-bulk-del-hl').on('click', function() {
                const codes = $('#rmm-del-codes').val().trim();
                if (!codes) { flash('#rmm-del-status', 'Введите хотя бы один код языка', 'red'); return; }
                if (!confirm('Удалить hreflang-записи с указанными кодами со всех страниц?')) return;
                const btn = $(this);
                btn.prop('disabled', true).text('Удаление...');
                $.post(ajaxurl, {action: 'rmm_bulk_delete_hreflang', nonce, lang_codes: codes}, function(res) {
                    btn.prop('disabled', false).text('Удалить из всех страниц');
                    if (res.success) {
                        flash('#rmm-del-status', 'Удалено из ' + res.data.count + ' страниц. Перезагрузите страницу.', 'green');
                        prependLogRow('Удаление hreflang', codes.replace(/[\r\n]+/g, ', '), null, res.data.count);
                    } else {
                        flash('#rmm-del-status', 'Ошибка: ' + (res.data || '?'), 'red');
                    }
                });
            });

            // ── Журнал ────────────────────────────────────────────────────────

            $('#rmm-clear-log').on('click', function() {
                if (!confirm('Очистить весь журнал операций?')) return;
                const btn = $(this);
                btn.prop('disabled', true);
                $.post(ajaxurl, {action: 'rmm_clear_log', nonce}, function(res) {
                    btn.prop('disabled', false);
                    if (res.success) {
                        $('#rmm-log-wrap').html('<p style="color:#666;font-style:italic;margin:8px 0 0;">Операции ещё не выполнялись.</p>');
                    }
                });
            });

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
                    $path          = parse_url($permalink, PHP_URL_PATH);
                    $query         = parse_url($permalink, PHP_URL_QUERY);
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
        $needle = trim($needle);
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

        $count          = 0;
        $html_lang_hits = 0;
        $hreflang_hits  = 0;
        foreach ($posts as $post) {
            $pid = $post->ID;

            if ($replace_html_lang) {
                $lang = get_post_meta($pid, '_regional_lang', true);
                if ($this->langCodesMatch($lang, $old_lang)) {
                    update_post_meta($pid, '_regional_lang', $new_lang);
                    $count++;
                    $html_lang_hits++;
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
                            $hreflang_hits++;
                        }
                    }
                    unset($h);
                    if ($updated) {
                        update_post_meta($pid, '_regional_hreflang', $hreflang);
                    }
                }
            }
        }

        wp_send_json_success([
            'count'     => $count,
            'html_lang' => $html_lang_hits,
            'hreflang'  => $hreflang_hits,
        ]);
    }

    public function ajaxBulkAddHreflang(): void
    {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', self::NONCE_ACTION) || !current_user_can('manage_options')) {
            wp_send_json_error('Ошибка безопасности');
        }

        $entries_raw = json_decode(stripslashes($_POST['entries'] ?? '[]'), true);
        if (!is_array($entries_raw) || empty($entries_raw)) {
            wp_send_json_error('Нет hreflang-записей');
        }

        $mode  = in_array($_POST['mode'] ?? '', ['merge', 'replace'], true) ? $_POST['mode'] : 'merge';
        $posts = $this->getAllPosts();

        $affected = [];
        foreach ($posts as $post) {
            $added = $this->applyHreflangToPost($post->ID, $entries_raw, $mode);
            if ($added > 0) {
                $affected[] = ['id' => $post->ID, 'title' => $post->post_title ?: '(no title)'];
            }
        }

        $this->addLogEntry([
            'time'    => current_time('timestamp'),
            'action'  => 'bulk_add_all',
            'label'   => 'Добавить на все',
            'mode'    => $mode,
            'entries' => $entries_raw,
            'count'   => count($affected),
            'details' => $affected,
        ]);

        wp_send_json_success(['count' => count($affected)]);
    }

    public function ajaxSelectiveAddHreflang(): void
    {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', self::NONCE_ACTION) || !current_user_can('manage_options')) {
            wp_send_json_error('Ошибка безопасности');
        }

        $entries_raw = json_decode(stripslashes($_POST['entries'] ?? '[]'), true);
        $post_ids    = json_decode(stripslashes($_POST['post_ids'] ?? '[]'), true);

        if (!is_array($entries_raw) || empty($entries_raw)) {
            wp_send_json_error('Нет hreflang-записей');
        }

        $post_ids = is_array($post_ids) ? array_map('intval', $post_ids) : [];
        if (empty($post_ids)) {
            wp_send_json_error('Не выбраны страницы');
        }

        $mode = in_array($_POST['mode'] ?? '', ['merge', 'replace'], true) ? $_POST['mode'] : 'merge';

        $affected = [];
        foreach ($post_ids as $pid) {
            if (!$pid || !get_post($pid) || !current_user_can('edit_post', $pid)) {
                continue;
            }
            $added = $this->applyHreflangToPost($pid, $entries_raw, $mode);
            if ($added > 0) {
                $p        = get_post($pid);
                $affected[] = ['id' => $pid, 'title' => $p->post_title ?: '(no title)'];
            }
        }

        $this->addLogEntry([
            'time'    => current_time('timestamp'),
            'action'  => 'selective_add',
            'label'   => 'Точечное добавление',
            'mode'    => $mode,
            'entries' => $entries_raw,
            'count'   => count($affected),
            'details' => $affected,
        ]);

        wp_send_json_success(['count' => count($affected)]);
    }

    public function ajaxBulkDeleteHreflang(): void
    {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', self::NONCE_ACTION) || !current_user_can('manage_options')) {
            wp_send_json_error('Ошибка безопасности');
        }

        $raw   = sanitize_text_field(wp_unslash($_POST['lang_codes'] ?? ''));
        $codes = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $raw))));

        if (empty($codes)) {
            wp_send_json_error('Укажите хотя бы один код');
        }

        $posts    = $this->getAllPosts();
        $affected = [];

        foreach ($posts as $post) {
            $pid      = $post->ID;
            $hreflang = get_post_meta($pid, '_regional_hreflang', true);
            if (!is_array($hreflang) || empty($hreflang)) {
                continue;
            }

            $filtered = array_values(array_filter($hreflang, function ($h) use ($codes) {
                if (!is_array($h)) {
                    return false;
                }
                foreach ($codes as $code) {
                    if (strcasecmp($h['lang'] ?? '', $code) === 0) {
                        return false;
                    }
                }
                return true;
            }));

            if (count($filtered) !== count($hreflang)) {
                if (!empty($filtered)) {
                    update_post_meta($pid, '_regional_hreflang', $filtered);
                } else {
                    delete_post_meta($pid, '_regional_hreflang');
                }
                $affected[] = ['id' => $pid, 'title' => $post->post_title ?: '(no title)'];
            }
        }

        $this->addLogEntry([
            'time'    => current_time('timestamp'),
            'action'  => 'bulk_delete_hreflang',
            'label'   => 'Удаление hreflang',
            'codes'   => $codes,
            'count'   => count($affected),
            'details' => $affected,
        ]);

        wp_send_json_success(['count' => count($affected)]);
    }

    public function ajaxClearLog(): void
    {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', self::NONCE_ACTION) || !current_user_can('manage_options')) {
            wp_send_json_error('Ошибка безопасности');
        }
        delete_option(self::LOG_OPTION);
        wp_send_json_success();
    }
}
