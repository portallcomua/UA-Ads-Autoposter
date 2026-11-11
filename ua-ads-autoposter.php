<?php
/**
 * Plugin Name: UA Ads Autoposter (Цифрові товари та софт)
 * Description: Єдина форма для підготовки, черги автопостингу та звітності розміщення оголошень.
 * Version: 1.4.0
 * Author: Custom Dev
 * Text Domain: ua-ads-autoposter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UA_Ads_Autoposter {

    private $api_key = 'ua-autopost-secret-key-2024';
    private $option_name = 'ua_ads_sites';

    public function __construct() {
        add_action( 'init', [ $this, 'register_cpt' ] );
        add_action( 'add_meta_boxes', [ $this, 'add_custom_metaboxes' ] );
        add_action( 'save_post', [ $this, 'save_ad_meta' ] );
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

        add_filter( 'manage_ua_ad_task_posts_columns', [ $this, 'add_report_columns' ] );
        add_action( 'manage_ua_ad_task_posts_custom_column', [ $this, 'render_report_columns' ], 10, 2 );

        // Адмін-сторінка керування дошками
        add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );

        // Одноразове перенесення старих дошок у налаштування
        add_action( 'admin_init', [ $this, 'maybe_seed_default_sites' ] );
    }

    // ============================================================
    // CPT
    // ============================================================
    public function register_cpt() {
        register_post_type( 'ua_ad_task', [
            'labels' => [
                'name'          => 'Автопостинг оголошень',
                'singular_name' => 'Оголошення',
                'add_new'       => 'Створити оголошення',
                'add_new_item'  => 'Нове оголошення для розсилки',
                'edit_item'     => 'Редагувати оголошення',
            ],
            'public'       => false,
            'show_ui'      => true,
            'menu_icon'    => 'dashicons-megaphone',
            'supports'     => [ 'title', 'editor', 'thumbnail' ],
        ] );
    }

    // ============================================================
    // КОЛОНКИ ЗВІТНОСТІ
    // ============================================================
    public function add_report_columns( $columns ) {
        return [
            'cb'             => $columns['cb'],
            'title'          => 'Назва оголошення',
            'ad_status'      => 'Статус черги',
            'ad_sites_count' => 'Розміщено на сайтах',
            'ad_posted_date' => 'Дата і час публікації',
            'date'           => 'Створено',
        ];
    }

    public function render_report_columns( $column, $post_id ) {
        if ( $column === 'ad_status' ) {
            $status = get_post_meta( $post_id, '_ua_ad_status', true ) ?: 'pending';
            if ( $status === 'done' ) {
                echo '<span style="color: #008a20; font-weight: bold;">✅ Розміщено</span>';
            } elseif ( $status === 'pending' ) {
                echo '<span style="color: #d63638; font-weight: bold;">⏳ В черзі</span>';
            } else {
                echo '<span style="color: #666;">⏸️ Пауза</span>';
            }
        }

        if ( $column === 'ad_sites_count' ) {
            $posted_sites = get_post_meta( $post_id, '_ua_ad_posted_sites', true ) ?: [];
            $target_sites = get_post_meta( $post_id, '_ua_ad_sites', true ) ?: [];
            echo '<strong>' . count( $posted_sites ) . ' з ' . count( $target_sites ) . '</strong>';
            if ( ! empty( $posted_sites ) ) {
                echo '<br><small style="color: #555;">' . esc_html( implode( ', ', $posted_sites ) ) . '</small>';
            }
        }

        if ( $column === 'ad_posted_date' ) {
            $posted_time = get_post_meta( $post_id, '_ua_ad_posted_time', true );
            echo $posted_time ? esc_html( $posted_time ) : '—';
        }
    }

    // ============================================================
    // МЕТАБОКС
    // ============================================================
    public function add_custom_metaboxes() {
        add_meta_box(
            'ua_ad_details',
            'Параметри продукту та майданчики публікації',
            [ $this, 'render_metabox' ],
            'ua_ad_task',
            'normal',
            'high'
        );
    }

    public function render_metabox( $post ) {
        wp_nonce_field( 'ua_ad_save_nonce', 'ua_ad_nonce' );

        $price        = get_post_meta( $post->ID, '_ua_ad_price', true );
        $currency     = get_post_meta( $post->ID, '_ua_ad_currency', true ) ?: 'UAH';
        $category     = get_post_meta( $post->ID, '_ua_ad_category', true );
        $demo_url     = get_post_meta( $post->ID, '_ua_ad_demo_url', true );
        $city         = get_post_meta( $post->ID, '_ua_ad_city', true ) ?: 'Київ';
        $target_sites = get_post_meta( $post->ID, '_ua_ad_sites', true ) ?: [];
        $status       = get_post_meta( $post->ID, '_ua_ad_status', true ) ?: 'pending';
        $report_log   = get_post_meta( $post->ID, '_ua_ad_report_log', true );
        $sites        = $this->get_sites();
        ?>
        <style>
            .ua-ad-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px; }
            .ua-ad-field label { display: block; font-weight: 600; margin-bottom: 5px; }
            .ua-ad-field input[type="text"], .ua-ad-field input[type="number"], .ua-ad-field select { width: 100%; }
            .ua-sites-box { background: #f9f9f9; padding: 15px; border: 1px solid #ddd; border-radius: 4px; }
            .ua-sites-list { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 10px; }
            .ua-report-box { background: #f0f6fc; border-left: 4px solid #2271b1; padding: 12px; margin-top: 15px; }
        </style>

        <div class="ua-ad-grid">
            <div class="ua-ad-field">
                <label>Тип продукту (Категорія):</label>
                <select name="ua_ad_category">
                    <option value="wp_plugin" <?php selected( $category, 'wp_plugin' ); ?>>WordPress плагін / Скрипт</option>
                    <option value="seo_soft" <?php selected( $category, 'seo_soft' ); ?>>Програма для просування / Парсер</option>
                    <option value="online_game" <?php selected( $category, 'online_game' ); ?>>Онлайн-гра / Ігровий акаунт</option>
                    <option value="it_service" <?php selected( $category, 'it_service' ); ?>>IT-послуга / Впровадження</option>
                </select>
            </div>

            <div class="ua-ad-field">
                <label>Ціна (залиште 0 для безкоштовного):</label>
                <div style="display: flex; gap: 5px;">
                    <input type="number" name="ua_ad_price" value="<?php echo esc_attr( $price ); ?>" placeholder="0">
                    <select name="ua_ad_currency" style="width: 100px;">
                        <option value="UAH" <?php selected( $currency, 'UAH' ); ?>>грн</option>
                        <option value="USD" <?php selected( $currency, 'USD' ); ?>>$</option>
                        <option value="EUR" <?php selected( $currency, 'EUR' ); ?>>€</option>
                    </select>
                </div>
            </div>

            <div class="ua-ad-field">
                <label>Посилання на демо / сайт / завантаження:</label>
                <input type="text" name="ua_ad_demo_url" value="<?php echo esc_attr( $demo_url ); ?>" placeholder="https://...">
            </div>

            <div class="ua-ad-field">
                <label>Місто (для локальних дошок):</label>
                <input type="text" name="ua_ad_city" value="<?php echo esc_attr( $city ); ?>">
            </div>
        </div>

        <div class="ua-sites-box">
            <label style="font-weight: 700; font-size: 14px;">
                <input type="checkbox" id="ua_select_all_sites"> Вибрати всі майданчики
            </label>
            <hr>
            <div class="ua-sites-list">
                <?php if ( empty( $sites ) ) : ?>
                    <p>Немає налаштованих дошок. <a href="<?php echo esc_url( admin_url( 'options-general.php?page=ua-ads-sites' ) ); ?>">Додайте їх у налаштуваннях</a>.</p>
                <?php else : ?>
                    <?php foreach ( $sites as $site ) : ?>
                        <label>
                            <input type="checkbox" class="ua-site-checkbox" name="ua_ad_sites[]" value="<?php echo esc_attr( $site['key'] ); ?>" <?php checked( in_array( $site['key'], $target_sites ) ); ?>>
                            <?php echo esc_html( $site['label'] ); ?>
                        </label>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div style="margin-top: 15px;">
            <label><strong>Статус черги автопостингу:</strong></label>
            <select name="ua_ad_status">
                <option value="pending" <?php selected( $status, 'pending' ); ?>>Очікує розміщення (В черзі)</option>
                <option value="done" <?php selected( $status, 'done' ); ?>>Розміщено</option>
                <option value="draft" <?php selected( $status, 'draft' ); ?>>Пауза / Чернетка</option>
            </select>
        </div>

        <?php if ( ! empty( $report_log ) ) : ?>
            <div class="ua-report-box">
                <strong>📊 Журнал виконання роботом:</strong>
                <pre style="margin: 8px 0 0; white-space: pre-wrap; font-family: inherit;"><?php echo esc_html( $report_log ); ?></pre>
            </div>
        <?php endif; ?>

        <script>
            document.getElementById('ua_select_all_sites').addEventListener('change', function(e) {
                document.querySelectorAll('.ua-site-checkbox').forEach(cb => cb.checked = e.target.checked);
            });
        </script>
        <?php
    }

    public function save_ad_meta( $post_id ) {
        if ( get_post_type( $post_id ) !== 'ua_ad_task' ) {
            return;
        }
        if ( ! isset( $_POST['ua_ad_nonce'] ) || ! wp_verify_nonce( $_POST['ua_ad_nonce'], 'ua_ad_save_nonce' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $fields = [
            '_ua_ad_price'    => sanitize_text_field( $_POST['ua_ad_price'] ?? '' ),
            '_ua_ad_currency' => sanitize_text_field( $_POST['ua_ad_currency'] ?? 'UAH' ),
            '_ua_ad_category' => sanitize_text_field( $_POST['ua_ad_category'] ?? '' ),
            '_ua_ad_demo_url' => esc_url_raw( $_POST['ua_ad_demo_url'] ?? '' ),
            '_ua_ad_city'     => sanitize_text_field( $_POST['ua_ad_city'] ?? 'Київ' ),
            '_ua_ad_status'   => sanitize_text_field( $_POST['ua_ad_status'] ?? 'pending' ),
        ];
        foreach ( $fields as $key => $val ) {
            update_post_meta( $post_id, $key, $val );
        }

        $sites = isset( $_POST['ua_ad_sites'] ) && is_array( $_POST['ua_ad_sites'] )
            ? array_map( 'sanitize_text_field', $_POST['ua_ad_sites'] )
            : [];
        update_post_meta( $post_id, '_ua_ad_sites', $sites );
    }

    // ============================================================
    // ДОШКИ — зберігання та отримання
    // ============================================================
    public function get_sites() {
        $sites = get_option( $this->option_name, [] );
        return is_array( $sites ) ? $sites : [];
    }

    public function maybe_seed_default_sites() {
        if ( get_option( 'ua_ads_sites_seeded' ) ) {
            return;
        }
        if ( empty( $this->get_sites() ) ) {
            $default = [
                [ 'key' => 'olx',           'label' => 'OLX.ua (Послуги / Ігри)',           'url' => 'https://www.olx.ua/uk/add/' ],
                [ 'key' => 'besplatka',     'label' => 'Besplatka.ua (IT та софт)',           'url' => 'https://besplatka.ua/' ],
                [ 'key' => 'ogolosha',      'label' => 'Ogolosha.ua',                         'url' => 'https://ogolosha.ua/uk/add' ],
                [ 'key' => 'obyava',        'label' => 'Obyava.ua',                           'url' => 'https://obyava.ua/' ],
                [ 'key' => 'freelancehunt', 'label' => 'Freelancehunt (Вітрина послуг)',      'url' => 'https://freelancehunt.com/' ],
                [ 'key' => 'itch',          'label' => 'Itch.io (Ігри та утиліти)',           'url' => 'https://itch.io/dashboard' ],
                [ 'key' => 'bboard',        'label' => 'Bboard.com.ua (IT, безкоштовно)',     'url' => 'https://www.bboard.com.ua/ru/add/' ],
            ];
            update_option( $this->option_name, $default );
        }
        update_option( 'ua_ads_sites_seeded', 1 );
    }

    // ============================================================
    // АДМІН-СТОРІНКА НАЛАШТУВАНЬ
    // ============================================================
    public function add_settings_page() {
        add_options_page(
            'UA Ads Дошки',
            'UA Ads Дошки',
            'manage_options',
            'ua-ads-sites',
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_settings() {
        register_setting( 'ua_ads_sites_group', $this->option_name, [
            'type' => 'array',
            'sanitize_callback' => [ $this, 'sanitize_sites' ],
        ] );
    }

    public function sanitize_sites( $input ) {
        $clean = [];
        $seen  = [];
        if ( is_array( $input ) ) {
            foreach ( $input as $row ) {
                $key   = isset( $row['key'] ) ? sanitize_key( $row['key'] ) : '';
                $label = isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '';
                $url   = isset( $row['url'] ) ? esc_url_raw( $row['url'] ) : '';
                if ( $key === '' || $url === '' ) {
                    continue;
                }
                if ( isset( $seen[ $key ] ) ) {
                    continue; // уникнення дублікатів ключів
                }
                $seen[ $key ] = true;
                $clean[] = [
                    'key'   => $key,
                    'label' => $label !== '' ? $label : $key,
                    'url'   => $url,
                ];
            }
        }
        return $clean;
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $sites = $this->get_sites();
        ?>
        <div class="wrap">
            <h1>Дошки для автопостингу</h1>
            <p>Ключ — латиницею, без пробілів. Використовується ботом для звітів. URL — сторінка, де відкривається форма публікації оголошення.</p>
            <form method="post" action="options.php">
                <?php settings_fields( 'ua_ads_sites_group' ); ?>
                <table class="widefat" id="ua-sites-table">
                    <thead>
                        <tr>
                            <th style="width: 15%;">Ключ</th>
                            <th style="width: 30%;">Назва (для форми)</th>
                            <th style="width: 45%;">URL сторінки публікації</th>
                            <th style="width: 10%;"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( empty( $sites ) ) : ?>
                        <tr class="ua-empty-row"><td colspan="4">Поки що немає дошок. Додайте першу нижче.</td></tr>
                    <?php else : ?>
                        <?php foreach ( $sites as $i => $site ) : ?>
                            <tr>
                                <td><input type="text" name="<?php echo esc_attr( $this->option_name ); ?>[<?php echo $i; ?>][key]" value="<?php echo esc_attr( $site['key'] ); ?>" required></td>
                                <td><input type="text" name="<?php echo esc_attr( $this->option_name ); ?>[<?php echo $i; ?>][label]" value="<?php echo esc_attr( $site['label'] ); ?>" style="width: 100%;"></td>
                                <td><input type="url" name="<?php echo esc_attr( $this->option_name ); ?>[<?php echo $i; ?>][url]" value="<?php echo esc_attr( $site['url'] ); ?>" required style="width: 100%;"></td>
                                <td><button type="button" class="button ua-remove-row">Видалити</button></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
                <p><button type="button" class="button" id="ua-add-row">+ Додати дошку</button></p>
                <?php submit_button( 'Зберегти дошки' ); ?>
            </form>
        </div>
        <script>
        (function() {
            const optName = '<?php echo esc_js( $this->option_name ); ?>';
            const tbody = document.querySelector('#ua-sites-table tbody');

            function nextIndex() {
                return tbody.querySelectorAll('tr').length;
            }

            document.getElementById('ua-add-row').addEventListener('click', function() {
                const empty = tbody.querySelector('.ua-empty-row');
                if (empty) empty.remove();
                const idx = nextIndex();
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td><input type="text" name="${optName}[${idx}][key]" required></td>
                    <td><input type="text" name="${optName}[${idx}][label]" style="width:100%;"></td>
                    <td><input type="url" name="${optName}[${idx}][url]" required style="width:100%;"></td>
                    <td><button type="button" class="button ua-remove-row">Видалити</button></td>`;
                tbody.appendChild(tr);
            });

            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('ua-remove-row')) {
                    e.target.closest('tr').remove();
                }
            });
        })();
        </script>
        <?php
    }

    // ============================================================
    // REST API
    // ============================================================
    public function register_rest_routes() {
        register_rest_route( 'ua-autopost/v1', '/tasks', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_pending_tasks' ],
            'permission_callback' => [ $this, 'check_api_key' ],
        ] );
        register_rest_route( 'ua-autopost/v1', '/complete/(?P<id>\d+)', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'mark_task_done' ],
            'permission_callback' => [ $this, 'check_api_key' ],
        ] );
        register_rest_route( 'ua-autopost/v1', '/sites', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_sites_endpoint' ],
            'permission_callback' => [ $this, 'check_api_key' ],
        ] );
    }

    public function check_api_key( $request ) {
        return $request->get_header( 'X-API-Key' ) === $this->api_key;
    }

    public function get_pending_tasks() {
        $posts = get_posts( [
            'post_type'   => 'ua_ad_task',
            'post_status' => 'publish',
            'numberposts' => 10,
            'meta_key'    => '_ua_ad_status',
            'meta_value'  => 'pending',
        ] );

        $tasks = [];
        foreach ( $posts as $post ) {
            $tasks[] = [
                'id'          => $post->ID,
                'title'       => $post->post_title,
                'description' => wp_strip_all_tags( $post->post_content ),
                'price'       => get_post_meta( $post->ID, '_ua_ad_price', true ),
                'currency'    => get_post_meta( $post->ID, '_ua_ad_currency', true ),
                'category'    => get_post_meta( $post->ID, '_ua_ad_category', true ),
                'demo_url'    => get_post_meta( $post->ID, '_ua_ad_demo_url', true ),
                'city'        => get_post_meta( $post->ID, '_ua_ad_city', true ),
                'sites'       => get_post_meta( $post->ID, '_ua_ad_sites', true ),
                'image'       => get_the_post_thumbnail_url( $post->ID, 'full' ),
            ];
        }
        return rest_ensure_response( $tasks );
    }

    public function get_sites_endpoint() {
        return rest_ensure_response( $this->get_sites() );
    }

    public function mark_task_done( $request ) {
        $post_id = (int) $request['id'];
        if ( get_post_type( $post_id ) !== 'ua_ad_task' ) {
            return new WP_Error( 'not_found', 'Оголошення не знайдено', [ 'status' => 404 ] );
        }

        $body         = $request->get_json_params();
        $posted_sites = isset( $body['posted_sites'] ) && is_array( $body['posted_sites'] )
            ? array_map( 'sanitize_text_field', $body['posted_sites'] )
            : [];
        $log_text     = isset( $body['log'] ) ? sanitize_textarea_field( $body['log'] ) : '';
        $current_time = current_time( 'd.m.Y H:i' );

        update_post_meta( $post_id, '_ua_ad_status', 'done' );
        update_post_meta( $post_id, '_ua_ad_posted_sites', $posted_sites );
        update_post_meta( $post_id, '_ua_ad_posted_time', $current_time );
        update_post_meta( $post_id, '_ua_ad_report_log', "Час завершення: {$current_time}\n{$log_text}" );

        return rest_ensure_response( [ 'success' => true ] );
    }
}

new UA_Ads_Autoposter();