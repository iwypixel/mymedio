<?php
/**
 * My Medio – ÁRLISTA modul (a kombinált plugin része)
 * Eredetileg önálló plugin: "My Medio - Professzionális Árlista API Pro".
 *
 * Funkciók: [grouped_prices] shortcode, árlista API + cache/backup kezelés,
 * design opciók (bm_* prefix), statisztika, PDF/print.
 *
 * A közös infrastruktúra (plugin-fejléc, SVG MIME engedély, admin menü, admin
 * asset betöltés, frontend CSS betöltés, admin értesítések) a fő plugin
 * fájlban található: mymedio-combined.php
 *
 * Az admin oldal tartalmát a fő fájl tabos felülete hívja meg az alábbi
 * render függvényeken keresztül:
 *   - bm_render_help_cards()   (Shortcode segédlet – Árlista, key szűrővel)
 *   - bm_render_design_form()  (Design beállítások – Árlista)
 *   - bm_render_stats()        (Statisztika – Árlista)
 *   - bm_render_api_form()     (API beállítások – Árlista)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 1. KATTINTÁS STATISZTIKA
 */
add_action('wp_ajax_bm_track_click', 'bm_track_click_handler');
add_action('wp_ajax_nopriv_bm_track_click', 'bm_track_click_handler');

function bm_track_click_handler() {
    $service  = isset($_POST['service']) ? sanitize_text_field($_POST['service']) : '';
    $category = isset($_POST['category']) ? sanitize_text_field($_POST['category']) : 'Nincs megadva';

    if ($service) {
        $stats = get_option('bm_click_stats', []);

        if (!isset($stats[$service])) {
            $stats[$service] = [
                'count' => 1,
                'cat'   => $category
            ];
        } else {
            $stats[$service]['count'] = isset($stats[$service]['count']) ? (int) $stats[$service]['count'] + 1 : 1;
            $stats[$service]['cat']   = $category;
        }

        update_option('bm_click_stats', $stats);
    }

    wp_die();
}

/**
 * 2. OLDALMEGTEKINTÉS STATISZTIKA
 */
function bm_track_list_view($category = '') {
    static $tracked = [];

    $key = $category ? mb_strtolower(trim($category)) : '__all__';

    if (isset($tracked[$key])) {
        return;
    }

    $tracked[$key] = true;

    $stats = get_option('bm_view_stats', [
        'all'        => 0,
        'categories' => []
    ]);

    if (!is_array($stats)) {
        $stats = [
            'all'        => 0,
            'categories' => []
        ];
    }

    if ($category === '') {
        $stats['all'] = isset($stats['all']) ? (int) $stats['all'] + 1 : 1;
    } else {
        if (!isset($stats['categories']) || !is_array($stats['categories'])) {
            $stats['categories'] = [];
        }

        if (!isset($stats['categories'][$category])) {
            $stats['categories'][$category] = 1;
        } else {
            $stats['categories'][$category] = (int) $stats['categories'][$category] + 1;
        }
    }

    update_option('bm_view_stats', $stats);
}

/**
 * (Az admin asset betöltés és a frontend CSS betöltés a fő plugin fájlba került.)
 */

/**
 * 5. ALAPÉRTÉKEK
 */
function bm_get_fallback_value($id) {
    $defaults = [
        'bm_font_family'            => 'Poppins',
        'bm_col_primary'            => '#A57884',
        'bm_col_border'             => '#9D9D9D',
        'bm_cta1_bg'                => '#A57884',
        'bm_cta1_txt'               => '#FFFFFF',
        'bm_cta2_txt'               => '#A57884',
        'bm_cta2_brd'               => '#A57884',
        'bm_cta2_hover_bg'          => '#A57884',
        'bm_cta2_hover_txt'         => '#FFFFFF',
        'bm_pdf_bg'                 => '#A57884',
        'bm_pdf_txt'                => '#FFFFFF',
        'bm_pdf_brd'                => '#A57884',
        'bm_pdf_hover_bg'           => '#8E6671',
        'bm_pdf_hover_txt'          => '#FFFFFF',
        'bm_pdf_label'              => 'Letöltés / PDF',
        'bm_no_res_txt'             => 'Nincs találat a keresésre',
        'bm_no_res_size'            => '16',
        'bm_no_res_col'             => '#888888',
        'bm_size_h3'                => '13',
        'bm_size_service'           => '14',
        'bm_size_desc'              => '11',
        'bm_size_price'             => '13',
        'bm_size_btn'               => '13',
        'bm_spacing'                => '10',
        'bm_search_placeholder'     => 'Keresés szakterület vagy vizsgálat nevére (pl. kardiológia, ultrahang...)',
        'bm_icon_search_url'        => '',
        'bm_icon_search_manual'     => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>',
        'bm_icon_plus_url'          => '',
        'bm_icon_plus_manual'       => '+',
        'bm_layout_mode'            => 'list',
        'bm_show_pdf'               => 'yes'
    ];

    return isset($defaults[$id]) ? $defaults[$id] : '';
}

function bm_get_safe_val($id) {
    $val = get_option($id);
    return ($val === '' || $val === null) ? bm_get_fallback_value($id) : $val;
}

/**
 * „Csak recepción foglalható” vizsgálat?
 *
 * A MyMedio API nem küld külön foglalhatóság mezőt: azoknál a vizsgálatoknál,
 * amelyek csak recepción foglalhatók, egyszerűen üres a `link` mező. Ezeknél
 * a foglalás gomb helyett a telefonos foglalás blokkja jelenik meg, a leírás
 * (info_text) pedig mindig látszik.
 */
function bm_is_reception_only($item) {
    $link = isset($item['link']) ? trim((string) $item['link']) : '';

    return ($link === '' || $link === '#' || strpos($link, 'http') !== 0);
}

/**
 * 6. API META ÁLLAPOT
 */
function bm_set_api_state($state = []) {
    $defaults = [
        'status'     => 'unknown',
        'message'    => '',
        'last_error' => '',
        'source'     => '',
        'checked_at' => current_time('mysql')
    ];

    update_option('bm_api_state', wp_parse_args($state, $defaults));
}

function bm_get_api_state() {
    $state = get_option('bm_api_state', []);
    return wp_parse_args($state, [
        'status'     => 'unknown',
        'message'    => '',
        'last_error' => '',
        'source'     => '',
        'checked_at' => ''
    ]);
}

/**
 * 6/b. SZINKRONIZÁLÁSI DIAGNOSZTIKA (az utolsó VALÓDI API-lekérés eredménye)
 * Csak akkor frissül, amikor ténylegesen történik HTTP-hívás (nem gyorsítótárból).
 */
function bm_set_fetch_diag($diag = []) {
    $diag = wp_parse_args($diag, [
        'url'    => '',
        'http'   => 0,
        'ok'     => false,
        'count'  => 0,
        'msg'    => '',
        'source' => '',
        'raw'    => '',
        'time'   => current_time('mysql'),
    ]);
    update_option('bm_fetch_diag', $diag);
}

function bm_get_fetch_diag() {
    return get_option('bm_fetch_diag', []);
}

/**
 * 7. API ADATOK + HIBAKEZELÉS + FALLBACK
 */
function bm_get_api_data() {
    $token = get_option('bm_api_token');
    $url   = get_option('bm_api_url');

    if (!$token || !$url) {
        bm_set_api_state([
            'status'     => 'empty',
            'message'    => 'Az API URL vagy token nincs megadva.',
            'last_error' => 'Hiányzó API URL vagy token.',
            'source'     => 'none'
        ]);
        bm_set_fetch_diag([
            'url'    => $url ? (string) $url : '',
            'http'   => 0,
            'ok'     => false,
            'count'  => 0,
            'msg'    => 'Hiányzó API URL vagy token.',
            'source' => 'none',
        ]);
        return false;
    }

    $cached = get_transient('bm_cache_v3');
    if ($cached) {
        bm_set_api_state([
            'status'     => 'live',
            'message'    => 'Az árlista gyorsítótárból töltődött be.',
            'last_error' => '',
            'source'     => 'cache'
        ]);
        // A diagnosztikát NEM írjuk felül gyorsítótár-találatkor – az utolsó valódi lekérés marad látható.
        return $cached;
    }

    $res = wp_remote_get($url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $token
        ],
        'timeout' => 15
    ]);

    $http_code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
    $raw_body  = is_wp_error($res) ? '' : (string) wp_remote_retrieve_body($res);

    if (!is_wp_error($res) && $http_code === 200) {
        $data = json_decode($raw_body, true);

        if (isset($data['response']) && is_array($data['response']) && !empty($data['response'])) {
            set_transient('bm_cache_v3', $data, 3600);
            update_option('bm_permanent_price_backup', $data);
            update_option('bm_last_sync_time', current_time('mysql'));

            bm_set_api_state([
                'status'     => 'live',
                'message'    => 'Az API kapcsolat aktív, az árlista élő adatból töltődött be.',
                'last_error' => '',
                'source'     => 'live'
            ]);
            bm_set_fetch_diag([
                'url'    => $url,
                'http'   => $http_code,
                'ok'     => true,
                'count'  => count($data['response']),
                'msg'    => count($data['response']) . ' tétel sikeresen beolvasva.',
                'source' => 'live',
            ]);

            return $data;
        }

        $backup = get_option('bm_permanent_price_backup');
        if ($backup && isset($backup['response']) && is_array($backup['response']) && !empty($backup['response'])) {
            bm_set_api_state([
                'status'     => 'backup',
                'message'    => 'Az API válasz üres volt, ezért a rendszer a mentett backup adatot használja.',
                'last_error' => 'Üres vagy hibás API válasz.',
                'source'     => 'backup'
            ]);
            bm_set_fetch_diag([
                'url'    => $url,
                'http'   => $http_code,
                'ok'     => false,
                'count'  => 0,
                'msg'    => 'Az API válaszolt (HTTP 200), de a response tömb üres volt – mentett backup használatban.',
                'source' => 'backup',
                'raw'    => mb_substr($raw_body, 0, 800),
            ]);
            return $backup;
        }

        bm_set_api_state([
            'status'     => 'empty',
            'message'    => 'Az API válaszolt, de nem érkezett használható adat.',
            'last_error' => 'Az API válaszolt, de a response tömb üres vagy hibás.',
            'source'     => 'none'
        ]);
        bm_set_fetch_diag([
            'url'    => $url,
            'http'   => $http_code,
            'ok'     => false,
            'count'  => 0,
            'msg'    => 'Az API válaszolt (HTTP 200), de a response tömb üres vagy hibás.',
            'source' => 'none',
            'raw'    => mb_substr($raw_body, 0, 800),
        ]);
        return false;
    }

    $error_message = is_wp_error($res) ? $res->get_error_message() : 'HTTP hiba: ' . $http_code;
    $backup = get_option('bm_permanent_price_backup');

    if ($backup && isset($backup['response']) && is_array($backup['response']) && !empty($backup['response'])) {
        bm_set_api_state([
            'status'     => 'backup',
            'message'    => 'Az API jelenleg nem elérhető, ezért az oldal a mentett backup adatot mutatja.',
            'last_error' => $error_message,
            'source'     => 'backup'
        ]);
        bm_set_fetch_diag([
            'url'    => $url,
            'http'   => $http_code,
            'ok'     => false,
            'count'  => 0,
            'msg'    => $error_message . ' – mentett backup használatban.',
            'source' => 'backup',
            'raw'    => mb_substr($raw_body, 0, 800),
        ]);
        return $backup;
    }

    bm_set_api_state([
        'status'     => 'empty',
        'message'    => 'Az API jelenleg nem elérhető, és nincs használható mentett adat sem.',
        'last_error' => $error_message,
        'source'     => 'none'
    ]);
    bm_set_fetch_diag([
        'url'    => $url,
        'http'   => $http_code,
        'ok'     => false,
        'count'  => 0,
        'msg'    => $error_message . ' – és nincs használható mentett adat sem.',
        'source' => 'none',
        'raw'    => mb_substr($raw_body, 0, 800),
    ]);

    return false;
}

function bm_hungarian_sort(&$array, $key_mode = false) {
    if ($key_mode) {
        uksort($array, 'bmm_hu_compare');
    } else {
        usort($array, function($a, $b) {
            $valA = is_array($a) && isset($a['szolgaltatas']) ? $a['szolgaltatas'] : (string) $a;
            $valB = is_array($b) && isset($b['szolgaltatas']) ? $b['szolgaltatas'] : (string) $b;
            return bmm_hu_compare($valA, $valB);
        });
    }
}

/**
 * 8. LOGÓ SEGÉDFÜGGVÉNYEK - CSAK PRINTHEZ
 */
function bm_get_site_logo_url() {
    $custom_logo_id = get_theme_mod('custom_logo');

    if ($custom_logo_id) {
        $logo = wp_get_attachment_image_src($custom_logo_id, 'full');
        if (!empty($logo[0])) {
            return $logo[0];
        }
    }

    return '';
}

function bm_get_print_logo_html() {
    $logo_url = bm_get_site_logo_url();
    if (!$logo_url) {
        return '';
    }

    return '<div class="bm-print-logo"><img src="' . esc_url($logo_url) . '" alt="' . esc_attr(get_bloginfo('name')) . '"></div>';
}

/**
 * 9. ADMIN MENÜ
 * (A közös "My Medio" menü a fő plugin fájlban van regisztrálva.)
 */

/**
 * 10. BEÁLLÍTÁSOK REGISZTRÁLÁSA + MŰVELETEK
 */
add_action('admin_init', function() {
    register_setting('bm_api_settings_group', 'bm_api_token');
    register_setting('bm_api_settings_group', 'bm_api_url');

    $opts = [
        'bm_font_family',
        'bm_col_primary',
        'bm_col_border',
        'bm_size_h3',
        'bm_size_service',
        'bm_size_desc',
        'bm_size_price',
        'bm_size_btn',
        'bm_spacing',
        'bm_cta1_bg',
        'bm_cta1_txt',
        'bm_cta2_txt',
        'bm_cta2_brd',
        'bm_cta2_hover_bg',
        'bm_cta2_hover_txt',
        'bm_pdf_bg',
        'bm_pdf_txt',
        'bm_pdf_brd',
        'bm_pdf_hover_bg',
        'bm_pdf_hover_txt',
        'bm_pdf_label',
        'bm_no_res_txt',
        'bm_no_res_size',
        'bm_no_res_col',
        'bm_search_placeholder',
        'bm_icon_search_url',
        'bm_icon_search_manual',
        'bm_icon_plus_url',
        'bm_icon_plus_manual',
        'bm_layout_mode',
        'bm_show_pdf',
        'bmm_phone_label',
        'bmm_phone_number',
        'bmm_phone_notice'
    ];

    foreach ($opts as $opt) {
        register_setting('bm_design_settings_group', $opt);
    }

    if (isset($_GET['action']) && $_GET['action'] === 'bm_sync_now') {
        check_admin_referer('bm_sync_action');
        delete_transient('bm_cache_v3');
        bm_get_api_data();
        wp_redirect(admin_url('admin.php?page=bm-mymedio&tab=api&bm_sync=success'));
        exit;
    }

    if (isset($_GET['action']) && $_GET['action'] === 'bm_reset_stats') {
        check_admin_referer('bm_reset_stats_action');
        update_option('bm_view_stats', [
            'all'        => 0,
            'categories' => []
        ]);
        update_option('bm_click_stats', []);
        wp_redirect(admin_url('admin.php?page=bm-mymedio&tab=stats&bm_reset=success'));
        exit;
    }
});

/**
 * 11. ADMIN FIGYELMEZTETÉS
 * (Az összevont admin értesítés a fő plugin fájlban van.)
 */

/**
 * 12. DIZÁJN VÁLTOZÓK ÉS FONT BETÖLTÉSE + PRINT CSS
 */
add_action('wp_head', 'bm_print_frontend_dynamic_styles', 99);

function bm_print_frontend_dynamic_styles() {
    $font = bm_get_safe_val('bm_font_family');
    $google_fonts = [
        'Poppins', 'Roboto', 'Open Sans', 'Montserrat', 'Lato', 'Oswald', 'Raleway',
        'Playfair Display', 'Ubuntu', 'Nunito', 'Bebas Neue', 'Barlow', 'Inter',
        'Work Sans', 'DM Sans', 'Manrope', 'Mulish', 'Source Sans 3', 'Merriweather',
        'Libre Baskerville', 'Figtree', 'Plus Jakarta Sans', 'Archivo', 'Kanit',
        'Assistant', 'Quicksand', 'Rubik'
    ];

    if (in_array($font, $google_fonts, true)) {
        echo '<link href="https://fonts.googleapis.com/css2?family=' . esc_attr(str_replace(' ', '+', $font)) . ':wght@300;400;500;600;700&display=swap" rel="stylesheet">';
    }

    echo '<style>
        .price-list-container.bmm-arlista{
            --bm-font-family:' . "'" . esc_html($font) . "'" . ',sans-serif;
            --bm-accent:' . esc_html(bm_get_safe_val('bm_col_primary')) . ';
            --bm-border:' . esc_html(bm_get_safe_val('bm_col_border')) . ';
            --bm-title-size:' . (int) bm_get_safe_val('bm_size_h3') . 'px;
            --bm-service-size:' . (int) bm_get_safe_val('bm_size_service') . 'px;
            --bm-desc-size:' . (int) bm_get_safe_val('bm_size_desc') . 'px;
            --bm-price-size:' . (int) bm_get_safe_val('bm_size_price') . 'px;
            --bm-btn-size:' . (int) bm_get_safe_val('bm_size_btn') . 'px;
            --bm-spacing:' . (int) bm_get_safe_val('bm_spacing') . 'px;
            --bm-price-bg:' . esc_html(bm_get_safe_val('bm_cta1_bg')) . ';
            --bm-price-txt:' . esc_html(bm_get_safe_val('bm_cta1_txt')) . ';
            --bm-btn-txt:' . esc_html(bm_get_safe_val('bm_cta2_txt')) . ';
            --bm-btn-border:' . esc_html(bm_get_safe_val('bm_cta2_brd')) . ';
            --bm-btn-hover-bg:' . esc_html(bm_get_safe_val('bm_cta2_hover_bg')) . ';
            --bm-btn-hover-txt:' . esc_html(bm_get_safe_val('bm_cta2_hover_txt')) . ';
            --bm-pdf-bg:' . esc_html(bm_get_safe_val('bm_pdf_bg')) . ';
            --bm-pdf-txt:' . esc_html(bm_get_safe_val('bm_pdf_txt')) . ';
            --bm-pdf-border:' . esc_html(bm_get_safe_val('bm_pdf_brd')) . ';
            --bm-pdf-hover-bg:' . esc_html(bm_get_safe_val('bm_pdf_hover_bg')) . ';
            --bm-pdf-hover-txt:' . esc_html(bm_get_safe_val('bm_pdf_hover_txt')) . ';
            --bm-nores-col:' . esc_html(bm_get_safe_val('bm_no_res_col')) . ';
            --bm-nores-size:' . (int) bm_get_safe_val('bm_no_res_size') . 'px;
        }

        .bmm-arlista .service-name,
        .bmm-arlista .price-card-title{
            font-size:var(--bm-service-size) !important;
        }

        .bmm-arlista .service-description,
        .bmm-arlista .price-card-info{
            font-size:var(--bm-desc-size) !important;
        }

        .bmm-arlista .price-pill,
        .bmm-arlista .price-card-price{
            font-size:var(--bm-price-size) !important;
        }

        .bmm-arlista .bm-pdf-btn{
            background:var(--bm-pdf-bg) !important;
            color:var(--bm-pdf-txt) !important;
            border:1px solid var(--bm-pdf-border) !important;
            font-size:var(--bm-btn-size) !important;
        }

        .bmm-arlista .bm-pdf-btn:hover{
            background:var(--bm-pdf-hover-bg) !important;
            color:var(--bm-pdf-hover-txt) !important;
            border-color:var(--bm-pdf-hover-bg) !important;
        }

        .bmm-arlista .book-btn,
        .price-list-container.bmm-arlista a.bm-phone-link[href^="tel:"]{
            color:var(--bm-btn-txt) !important;
            border:1px solid var(--bm-btn-border) !important;
            font-size:var(--bm-btn-size) !important;
        }

        .bmm-arlista .book-btn:hover,
        .price-list-container.bmm-arlista a.bm-phone-link[href^="tel:"]:hover{
            background:var(--bm-btn-hover-bg) !important;
            color:var(--bm-btn-hover-txt) !important;
            border-color:var(--bm-btn-hover-bg) !important;
        }

        .bmm-arlista .bm-api-warning{
            margin:0 0 18px 0;
            padding:14px 16px;
            border-radius:12px;
            border:1px solid #e6c15a;
            background:#fff8e5;
            color:#5f4b00;
            font-family:' . "'" . esc_html($font) . "'" . ',sans-serif;
            font-size:13px;
            line-height:1.5;
        }

        .bmm-arlista .bm-api-error{
            margin:0 0 18px 0;
            padding:14px 16px;
            border-radius:12px;
            border:1px solid #d63638;
            background:#fff1f1;
            color:#7a1011;
            font-family:' . "'" . esc_html($font) . "'" . ',sans-serif;
            font-size:13px;
            line-height:1.5;
        }

        .bm-print-document,
        .bm-print-header,
        .bm-print-footer{
            display:none;
        }

        @media print{
            @page{
                margin:18mm 14mm 18mm 14mm;
            }

            body *{
                visibility:hidden !important;
            }

            .price-list-container,
            .price-list-container *{
                visibility:visible !important;
            }

            .price-list-container{
                position:absolute !important;
                left:0 !important;
                top:0 !important;
                width:100% !important;
                max-width:none !important;
                margin:0 !important;
                padding:0 !important;
                background:#fff !important;
            }

            .bm-print-document{
                display:block !important;
                color:#111 !important;
                font-family:' . "'" . esc_html($font) . "'" . ',sans-serif !important;
            }

            .bm-print-header{
                display:flex !important;
                flex-direction:column !important;
                align-items:center !important;
                justify-content:space-between !important;
                text-align:center !important;
                min-height:115mm !important;
                margin:0 0 12mm 0 !important;
                padding:2mm 0 0 0 !important;
                border:0 !important;
                box-sizing:border-box !important;
            }

            .bm-print-logo{
                text-align:center !important;
                margin:0 !important;
                width:100% !important;
            }

            .bm-print-logo img{
                max-width:165px !important;
                max-height:78px !important;
                width:auto !important;
                height:auto !important;
                opacity:1 !important;
                margin:0 auto !important;
                display:block !important;
            }

            .bm-print-header-main{
                width:100% !important;
                display:flex !important;
                flex-direction:column !important;
                align-items:center !important;
                justify-content:center !important;
                flex:1 1 auto !important;
            }

            .bm-print-title{
                font-size:28px !important;
                line-height:1.2 !important;
                font-weight:700 !important;
                letter-spacing:0.08em !important;
                text-transform:uppercase !important;
                color:#111 !important;
                margin:0 !important;
                text-align:center !important;
            }

            .bm-print-subtitle{
                font-size:13px !important;
                line-height:1.5 !important;
                color:#666 !important;
                margin:6px 0 0 0 !important;
                text-align:center !important;
            }

            .bm-print-content{
                display:block !important;
                padding-bottom:18mm !important;
            }

            .bm-print-section{
                margin:0 0 10mm 0 !important;
                break-inside:auto !important;
                page-break-inside:auto !important;
            }

            .bm-print-section-title{
                font-size:16px !important;
                font-weight:700 !important;
                text-transform:uppercase !important;
                letter-spacing:0.04em !important;
                color:#111 !important;
                margin:0 0 5mm 0 !important;
                padding:0 0 2mm 0 !important;
                border-bottom:1px solid #cfcfcf !important;
            }

            .bm-print-item{
                display:block !important;
                padding:3.2mm 0 !important;
                border-bottom:1px solid #ececec !important;
                break-inside:avoid !important;
                page-break-inside:avoid !important;
            }

            .bm-print-service-line{
                display:flex !important;
                align-items:flex-start !important;
                justify-content:space-between !important;
                gap:10mm !important;
            }

            .bm-print-service-name{
                display:block !important;
                flex:1 1 auto !important;
                font-size:12px !important;
                line-height:1.5 !important;
                font-weight:600 !important;
                color:#111 !important;
            }

            .bm-print-service-price{
                display:block !important;
                flex:0 0 auto !important;
                white-space:nowrap !important;
                font-size:12px !important;
                line-height:1.5 !important;
                font-weight:700 !important;
                color:#111 !important;
                text-align:right !important;
            }

            .bm-print-service-info{
                margin-top:1.5mm !important;
                font-size:10px !important;
                line-height:1.5 !important;
                color:#666 !important;
            }

            .bm-print-footer{
                display:flex !important;
                justify-content:space-between !important;
                align-items:center !important;
                position:fixed !important;
                left:0 !important;
                right:0 !important;
                bottom:0 !important;
                font-size:10px !important;
                color:#666 !important;
                background:#fff !important;
                padding-top:2mm !important;
                border-top:1px solid #d9d9d9 !important;
            }

            .bm-print-footer-left{
                text-align:left !important;
                line-height:1.4 !important;
            }

            .bm-print-footer-right{
                text-align:right !important;
                white-space:nowrap !important;
            }

            .bm-page-number:after{
                content:counter(page);
            }

            .price-topbar,
            .price-grid,
            .accordion-item,
            .price-item-row,
            #no-results-msg,
            .bm-pdf-btn,
            .search-icon,
            #price-search-input,
            .book-btn,
            .bm-phone-link,
            .bm-phone-notice,
            .bm-api-warning,
            .bm-api-error,
            .bm-corner-logo{
                display:none !important;
            }
        }
        }
    </style>';
}

/**
 * 13. ADMIN OLDAL
 */
/**
 * SHORTCODE SEGÉDLET – Árlista kártyák (key szűrővel).
 * A keresőmezőt, a panel-keretet és a JS-t a fő plugin fájl biztosítja.
 * A kártyák data-type="price" jelöléssel azonosítják magukat az egységes JS-hez.
 */
function bm_render_help_cards() {
    ?>
    <div id="bm-shortcode-list" class="bmm-shortcode-list">
        <div class="bm-shortcode-card bm-always-show" data-type="price" data-title="Összes kategória listája">
            <div class="bm-shortcode-top">
                <div class="bm-shortcode-title">Összes kategória listája</div>
                <div class="bm-shortcode-switch">
                    <button type="button" class="active" data-view="default">Alapértelmezett</button>
                    <button type="button" data-view="list">Harmonika</button>
                    <button type="button" data-view="grid">Grid</button>
                </div>
            </div>
            <div class="bm-shortcode-code-row">
                <code class="bm-shortcode-code">[grouped_prices]</code>
                <button type="button" class="button button-secondary bm-copy-dynamic-btn">Kimásolás</button>
            </div>
            <div class="bm-shortcode-key-row" style="margin-top:8px; display:flex; align-items:center; gap:8px;">
                <label style="font-size:12px; color:#666; white-space:nowrap;">Vizsgálat szűrő (key):</label>
                <input type="text" class="bm-key-input" placeholder="Írd ide a vizsgálatot, amit szeretnél" style="width:260px; padding:4px 8px; border:1px solid #dcdcde; border-radius:4px; font-size:12px;">
            </div>
            <div class="bm-shortcode-note">Az alapértelmezett a Design beállításokban megadott nézetet használja. A key mezővel leszűkítheted a vizsgálatokat Több vizsgálatot vesszővel sorolj fel (pl. <code>egyik vizsgálat, másik vizsgálat</code>).</div>
        </div>

        <?php
        $data = bm_get_api_data();

        if ($data && isset($data['response']) && is_array($data['response'])) {
            $cats = array_filter(array_unique(array_column($data['response'], 'szakterulet')));
            bm_hungarian_sort($cats);

            foreach ($cats as $cat) {
                $slug = mb_strtolower($cat);
                ?>
                <div class="bm-shortcode-card" data-type="price" data-title="<?php echo esc_attr($cat); ?>" data-category="<?php echo esc_attr($slug); ?>">
                    <div class="bm-shortcode-top">
                        <div class="bm-shortcode-title"><?php echo esc_html($cat); ?></div>
                        <div class="bm-shortcode-switch">
                            <button type="button" class="active" data-view="default">Alapértelmezett</button>
                            <button type="button" data-view="list">Harmonika</button>
                            <button type="button" data-view="grid">Grid</button>
                        </div>
                    </div>
                    <div class="bm-shortcode-code-row">
                        <code class="bm-shortcode-code">[grouped_prices category="<?php echo esc_attr($slug); ?>"]</code>
                        <button type="button" class="button button-secondary bm-copy-dynamic-btn">Kimásolás</button>
                    </div>
                    <div class="bm-shortcode-key-row" style="margin-top:8px; display:flex; align-items:center; gap:8px;">
                        <label style="font-size:12px; color:#666; white-space:nowrap;">Vizsgálat szűrő (key):</label>
                        <input type="text" class="bm-key-input" placeholder="Írd ide a vizsgálatot, amit szeretnél" style="width:260px; padding:4px 8px; border:1px solid #dcdcde; border-radius:4px; font-size:12px;">
                    </div>
                    <div class="bm-shortcode-note">Kapcsold át a kívánt nézetre, és a shortcode automatikusan frissül. A key mezővel leszűkítheted a vizsgálatokat Több vizsgálatot vesszővel sorolj fel (pl. <code>egyik vizsgálat, másik vizsgálat</code>).</div>
                </div>
                <?php
            }
        }
        ?>
    </div>
    <?php
}

/**
 * DESIGN BEÁLLÍTÁSOK – Árlista megjelenés (önálló űrlap, bm_design_settings_group).
 */
function bm_render_design_form() {
    $google_fonts = [
        'Poppins', 'Roboto', 'Open Sans', 'Montserrat', 'Lato', 'Oswald', 'Raleway',
        'Playfair Display', 'Ubuntu', 'Nunito', 'Bebas Neue', 'Barlow', 'Inter',
        'Work Sans', 'DM Sans', 'Manrope', 'Mulish', 'Source Sans 3', 'Merriweather',
        'Libre Baskerville', 'Figtree', 'Plus Jakarta Sans', 'Archivo', 'Kanit',
        'Assistant', 'Quicksand', 'Rubik', 'Arial', 'Verdana', 'Tahoma', 'Georgia'
    ];
    sort($google_fonts);
    ?>
    <form method="post" action="options.php">
        <?php settings_fields('bm_design_settings_group'); ?>

        <div class="bm-section-title">Elrendezés & Funkciók</div>
        <div class="bm-row">
            <div class="bm-unit">
                <label>Alap nézet:</label>
                <select name="bm_layout_mode">
                    <option value="list" <?php selected(get_option('bm_layout_mode'), 'list'); ?>>Harmonika</option>
                    <option value="grid" <?php selected(get_option('bm_layout_mode'), 'grid'); ?>>Kártyás (Grid)</option>
                </select>
            </div>
            <div class="bm-unit">
                <label>PDF Gomb:</label>
                <select name="bm_show_pdf">
                    <option value="yes" <?php selected(get_option('bm_show_pdf'), 'yes'); ?>>Látható</option>
                    <option value="no" <?php selected(get_option('bm_show_pdf'), 'no'); ?>>Rejtett</option>
                </select>
            </div>
        </div>

        <div class="bm-section-title">Globális megjelenés & Ikonok (Szerkeszthető + Feltölthető)</div>
        <div class="bm-row">
            <div class="bm-unit">
                <label>Betűtípus:</label>
                <select name="bm_font_family">
                    <?php foreach ($google_fonts as $font): ?>
                        <option value="<?php echo esc_attr($font); ?>" <?php selected(bm_get_safe_val('bm_font_family'), $font); ?>><?php echo esc_html($font); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php bm_icon_upload_render('Kereső ikon', 'bm_icon_search_url', 'bm_icon_search_manual'); ?>
            <?php bm_icon_upload_render('Plusz ikon', 'bm_icon_plus_url', 'bm_icon_plus_manual'); ?>
        </div>

        <div class="bm-section-title">Harmonika & Keretek</div>
        <div class="bm-row">
            <?php bm_color_input_render('Aktív szín', 'bm_col_primary'); ?>
            <?php bm_color_input_render('Keret szín', 'bm_col_border'); ?>
            <div class="bm-unit">
                <label>Cím méret (px):</label>
                <input type="number" name="bm_size_h3" value="<?php echo esc_attr(bm_get_safe_val('bm_size_h3')); ?>" style="width:70px;">
            </div>
            <div class="bm-unit">
                <label>Vizsgálat betűméret (px):</label>
                <input type="number" name="bm_size_service" value="<?php echo esc_attr(bm_get_safe_val('bm_size_service')); ?>" style="width:70px;">
            </div>
            <div class="bm-unit">
                <label>Leírás betűméret (px):</label>
                <input type="number" name="bm_size_desc" value="<?php echo esc_attr(bm_get_safe_val('bm_size_desc')); ?>" style="width:70px;">
            </div>
            <div class="bm-unit">
                <label>Ár betűméret (px):</label>
                <input type="number" name="bm_size_price" value="<?php echo esc_attr(bm_get_safe_val('bm_size_price')); ?>" style="width:70px;">
            </div>
            <div class="bm-unit">
                <label>Gombok betűméret (px):</label>
                <input type="number" name="bm_size_btn" value="<?php echo esc_attr(bm_get_safe_val('bm_size_btn')); ?>" style="width:70px;">
            </div>
            <div class="bm-unit">
                <label>Térköz (px):</label>
                <input type="number" name="bm_spacing" value="<?php echo esc_attr(bm_get_safe_val('bm_spacing')); ?>" style="width:70px;">
            </div>
        </div>

        <div class="bm-section-title">CTA Gombok (Ár pill & Foglalás)</div>
        <div class="bm-row">
            <?php bm_color_input_render('Ár háttér', 'bm_cta1_bg'); ?>
            <?php bm_color_input_render('Ár szöveg', 'bm_cta1_txt'); ?>
            <?php bm_color_input_render('Gomb szöveg', 'bm_cta2_txt'); ?>
            <?php bm_color_input_render('Gomb keret', 'bm_cta2_brd'); ?>
            <?php bm_color_input_render('Gomb hover BG', 'bm_cta2_hover_bg'); ?>
            <?php bm_color_input_render('Gomb hover TXT', 'bm_cta2_hover_txt'); ?>
        </div>

        <div class="bm-section-title">PDF gomb</div>
        <div class="bm-row">
            <div class="bm-unit" style="flex:2;">
                <label>Gomb felirata:</label>
                <input type="text" name="bm_pdf_label" value="<?php echo esc_attr(bm_get_safe_val('bm_pdf_label')); ?>" style="width:100%;">
            </div>
        </div>
        <div class="bm-row">
            <?php bm_color_input_render('PDF háttér', 'bm_pdf_bg'); ?>
            <?php bm_color_input_render('PDF szöveg', 'bm_pdf_txt'); ?>
            <?php bm_color_input_render('PDF keret', 'bm_pdf_brd'); ?>
            <?php bm_color_input_render('PDF hover háttér', 'bm_pdf_hover_bg'); ?>
            <?php bm_color_input_render('PDF hover szöveg', 'bm_pdf_hover_txt'); ?>
        </div>

        <div class="bm-section-title">Telefonos foglalás (csak recepción foglalható vizsgálatok)</div>
        <div class="bm-row">
            <div class="bm-unit" style="flex:2;">
                <label>Gomb felirata:</label>
                <input type="text" name="bmm_phone_label" value="<?php echo esc_attr(bmm_get_phone_label()); ?>" style="width:100%;">
            </div>
            <div class="bm-unit" style="flex:2;">
                <label>Hívható telefonszám:</label>
                <input type="text" name="bmm_phone_number" value="<?php echo esc_attr(bmm_get_phone_number()); ?>" style="width:100%;">
            </div>
            <div class="bm-unit" style="flex:3; min-width:260px;">
                <p style="margin:0; font-size:12px; color:#666;">
                    Azoknál a vizsgálatoknál jelenik meg a foglalás gomb helyett, amelyekhez
                    a MyMedio nem küld foglalási linket (foglalhatóság: „Csak recepción”).
                    Ugyanez a beállítás vonatkozik az orvosok listájára is.
                </p>
            </div>
        </div>
        <div class="bm-row">
            <div class="bm-unit" style="flex:1; width:100%;">
                <label>„!” jel szövege a vizsgálat neve mellett:</label>
                <textarea name="bmm_phone_notice" rows="2" style="width:100%;"><?php echo esc_textarea(get_option('bmm_phone_notice', BMM_PHONE_NOTICE_DEFAULT)); ?></textarea>
                <p style="margin:6px 0 0; font-size:12px; color:#666;">
                    A <code>%s</code> helyére automatikusan a fenti telefonszám kerül. Üresen hagyva az alapértelmezett szöveg jelenik meg.
                </p>
            </div>
        </div>

        <div class="bm-section-title">Szövegek & Hibajelzés</div>
        <div class="bm-row">
            <div class="bm-unit" style="flex:2;">
                <label>Kereső placeholder:</label>
                <input type="text" name="bm_search_placeholder" value="<?php echo esc_attr(bm_get_safe_val('bm_search_placeholder')); ?>" style="width:100%;">
            </div>
            <div class="bm-unit" style="flex:2;">
                <label>Hiba üzenet:</label>
                <input type="text" name="bm_no_res_txt" value="<?php echo esc_attr(bm_get_safe_val('bm_no_res_txt')); ?>" style="width:100%;">
            </div>
            <?php bm_color_input_render('Hiba szín', 'bm_no_res_col'); ?>
            <div class="bm-unit">
                <label>Hiba méret:</label>
                <input type="number" name="bm_no_res_size" value="<?php echo esc_attr(bm_get_safe_val('bm_no_res_size')); ?>" style="width:70px;">
            </div>
        </div>

        <?php submit_button('Árlista beállítások mentése'); ?>
    </form>
    <?php
}

/**
 * STATISZTIKA – Árlista (megtekintések + kattintások).
 */
function bm_render_stats() {
    $view_stats = get_option('bm_view_stats', [
        'all'        => 0,
        'categories' => []
    ]);
    $click_stats = get_option('bm_click_stats', []);
    ?>
    <div style="margin-top:10px; background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:8px;">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap;">
            <h3 style="margin:0;">Megtekintési statisztika</h3>
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=bm-mymedio&tab=stats&action=bm_reset_stats'), 'bm_reset_stats_action')); ?>" class="button bm-danger-btn" onclick="return confirm('Biztosan nullázod az árlista statisztikát?');">Árlista statisztika nullázása</a>
        </div>

        <div class="bm-stat-cards" style="margin-top:20px;">
            <div class="bm-stat-card">
                Összes kategória listája
                <strong><?php echo isset($view_stats['all']) ? (int) $view_stats['all'] : 0; ?></strong>
            </div>
            <div class="bm-stat-card">
                Mért kategóriák száma
                <strong><?php echo isset($view_stats['categories']) && is_array($view_stats['categories']) ? count($view_stats['categories']) : 0; ?></strong>
            </div>
        </div>

        <table class="wp-list-table widefat fixed striped" style="margin-bottom:30px;">
            <thead>
                <tr>
                    <th>Szakterület</th>
                    <th>Megtekintések</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if (isset($view_stats['categories']) && is_array($view_stats['categories']) && !empty($view_stats['categories'])) {
                    $cats = $view_stats['categories'];
                    arsort($cats);

                    foreach ($cats as $cat => $count) {
                        echo '<tr><td>' . esc_html($cat) . '</td><td><strong>' . (int) $count . '</strong></td></tr>';
                    }
                } else {
                    echo "<tr><td colspan='2'>Nincs még mért szakterület megtekintés.</td></tr>";
                }
                ?>
            </tbody>
        </table>

        <h3>Kattintás-statisztika</h3>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Szakterület</th>
                    <th>Szolgáltatás</th>
                    <th>Kattintások</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if (!empty($click_stats) && is_array($click_stats)) {
                    uasort($click_stats, function($a, $b) {
                        $a_count = is_array($a) && isset($a['count']) ? (int) $a['count'] : (int) $a;
                        $b_count = is_array($b) && isset($b['count']) ? (int) $b['count'] : (int) $b;
                        return $b_count <=> $a_count;
                    });

                    foreach ($click_stats as $name => $data) {
                        $count = is_array($data) && isset($data['count']) ? (int) $data['count'] : (int) $data;
                        $cat   = is_array($data) && isset($data['cat']) ? $data['cat'] : 'Nincs adat';

                        echo '<tr><td>' . esc_html($cat) . '</td><td>' . esc_html($name) . '</td><td><strong>' . $count . '</strong></td></tr>';
                    }
                } else {
                    echo "<tr><td colspan='3'>Nincs még mért kattintás.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
    <?php
}

/**
 * API BEÁLLÍTÁSOK – Árlista API hitelesítő űrlap (URL + token).
 * Az összevont szinkronizálási státusz a fő plugin fájlban (bmm_render_sync_status_block) jelenik meg.
 */
function bm_render_api_form() {
    // ── Legutóbbi szinkronizálás eredménye (diagnosztika) ──
    $diag = bm_get_fetch_diag();
    $last = get_option('bm_last_sync_time');
    if (!empty($diag)) {
        $diag_ok  = !empty($diag['ok']);
        $diag_200 = isset($diag['http']) && (int) $diag['http'] === 200;
        $diag_bg  = $diag_ok ? '#f0fcf1' : ($diag_200 ? '#fff8e5' : '#fff1f1');
        $diag_brd = $diag_ok ? '#00a32a' : ($diag_200 ? '#e6c15a' : '#d63638');
        ?>
        <div style="border:1px solid <?php echo esc_attr($diag_brd); ?>; background:<?php echo esc_attr($diag_bg); ?>; border-radius:8px; padding:14px 18px; margin-bottom:20px; font-size:13px;">
            <strong>🔍 Legutóbbi szinkronizálás eredménye</strong>
            <table style="margin-top:8px; width:100%; border-collapse:collapse; font-size:12px;">
                <tr>
                    <td style="padding:3px 8px 3px 0; color:#555; width:170px;">Hívott URL:</td>
                    <td><code style="word-break:break-all;"><?php echo esc_html($diag['url'] ?? '—'); ?></code></td>
                </tr>
                <tr>
                    <td style="padding:3px 8px 3px 0; color:#555;">HTTP státusz:</td>
                    <td><strong><?php echo esc_html(($diag['http'] ?? 0) ? $diag['http'] : '—'); ?></strong></td>
                </tr>
                <tr>
                    <td style="padding:3px 8px 3px 0; color:#555;">Eredmény:</td>
                    <td><?php echo esc_html($diag['msg'] ?? '—'); ?></td>
                </tr>
                <tr>
                    <td style="padding:3px 8px 3px 0; color:#555;">Beolvasott tételek:</td>
                    <td><strong><?php echo (int) ($diag['count'] ?? 0); ?></strong></td>
                </tr>
                <tr>
                    <td style="padding:3px 8px 3px 0; color:#555;">Utolsó sikeres frissítés:</td>
                    <td><strong><?php echo $last ? esc_html($last) : 'Soha'; ?></strong></td>
                </tr>
                <tr>
                    <td style="padding:3px 8px 3px 0; color:#555;">Ellenőrizve:</td>
                    <td><?php echo esc_html($diag['time'] ?? '—'); ?></td>
                </tr>
                <?php if (!empty($diag['raw']) && (int) ($diag['count'] ?? 0) === 0) : ?>
                <tr>
                    <td style="padding:6px 8px 3px 0; color:#555; vertical-align:top;">API válasz (részlet):</td>
                    <td><pre style="margin:0; font-size:11px; background:#f6f7f7; padding:8px; border-radius:4px; overflow-x:auto; max-height:120px; white-space:pre-wrap;"><?php echo esc_html($diag['raw']); ?></pre></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>
        <?php
    }
    ?>
    <form method="post" action="options.php">
        <?php settings_fields('bm_api_settings_group'); ?>
        <table class="form-table">
            <tr>
                <th>API URL</th>
                <td>
                    <input type="text" name="bm_api_url" value="<?php echo esc_attr(get_option('bm_api_url')); ?>" class="regular-text" style="width:100%;" placeholder="pl. https://app.kvery.io/query/api/SAJAT_TOKEN/v1.0.1/pricelist">
                    <p class="description">A MyMedio teljes árlista végpontja (<code>/pricelist</code>). Ezt a <code>[grouped_prices]</code> shortcode használja.</p>
                </td>
            </tr>
            <tr>
                <th>API Token</th>
                <td>
                    <input type="password" name="bm_api_token" value="<?php echo esc_attr(get_option('bm_api_token')); ?>" class="regular-text" style="width:100%;" autocomplete="new-password">
                    <p class="description">Az árlista lekéréséhez használt egyedi API token.</p>
                </td>
            </tr>
        </table>
        <?php submit_button('Árlista API mentése'); ?>
    </form>
    <?php
}

/**
 * 14. ADMIN SEGÉDFÜGGVÉNYEK
 */
function bm_color_input_render($label, $id) {
    $value = bm_get_safe_val($id);

    echo "<div class='bm-unit'>
            <label>" . esc_html($label) . ":</label>
            <div style='display:flex;gap:5px;'>
                <input type='text' id='" . esc_attr($id) . "_hex' name='" . esc_attr($id) . "' value='" . esc_attr($value) . "' style='width:75px;font-size:11px;'>
                <input type='color' value='" . esc_attr($value) . "' oninput='document.getElementById(\"" . esc_js($id) . "_hex\").value=this.value.toUpperCase()'>
            </div>
          </div>";
}

function bm_icon_upload_render($label, $id_url, $id_manual) {
    $url        = bm_get_safe_val($id_url);
    $manual     = bm_get_safe_val($id_manual);
    $preview_id = $id_url . '_prev';
    $preview    = $url ? "<img src='" . esc_url($url) . "' alt=''>" : $manual;

    echo "<div class='bm-unit' style='flex:1; min-width:320px;'>
            <label>" . esc_html($label) . " (SVG kód vagy feltöltés):</label>
            <div style='display:flex; gap:10px; align-items:center;'>
                <div class='bm-icon-preview' id='" . esc_attr($preview_id) . "'>" . $preview . "</div>
                <input type='text'
                       class='bm-icon-manual-input'
                       name='" . esc_attr($id_manual) . "'
                       value='" . esc_attr($manual) . "'
                       placeholder='SVG kód...'
                       oninput='document.getElementById(\"" . esc_js($preview_id) . "\").innerHTML = this.value || \"+\";'>
                <input type='hidden' name='" . esc_attr($id_url) . "' id='" . esc_attr($id_url) . "' value='" . esc_url($url) . "'>
                <button type='button' class='button bm-upload-btn' data-input='" . esc_attr($id_url) . "' data-preview='" . esc_attr($preview_id) . "'>Feltöltés</button>
            </div>
          </div>";
}

/**
 * 15. ÁR FORMÁZÁS
 */
function bm_parse_price($value) {
    if (is_int($value) || is_float($value)) {
        return (float) $value;
    }

    if (is_string($value)) {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        $value = str_replace(["\xc2\xa0", '&nbsp;', 'Ft', 'ft', 'FT', ' '], '', $value);
        $value = str_replace(',', '.', $value);

        if (is_numeric($value)) {
            return (float) $value;
        }
    }

    return null;
}

function bm_format_price($min, $max = null) {
    $min_val = bm_parse_price($min);
    $max_val = bm_parse_price($max);

    if ($min_val === null && is_string($min) && trim($min) !== '') {
        return esc_html(trim($min));
    }

    if ($min_val === null) {
        return 'Nincs ár megadva';
    }

    if ($max_val !== null && $min_val != $max_val) {
        return number_format($min_val, 0, ',', ' ') . ' - ' . number_format($max_val, 0, ',', ' ') . ' Ft';
    }

    return number_format($min_val, 0, ',', ' ') . ' Ft';
}

/**
 * 16. IKONOK FRONTENDHEZ
 */
function bm_get_frontend_search_icon_html() {
    $url    = bm_get_safe_val('bm_icon_search_url');
    $manual = bm_get_safe_val('bm_icon_search_manual');

    return $url ? '<img src="' . esc_url($url) . '" alt="">' : $manual;
}

function bm_get_frontend_plus_icon_html() {
    $url    = bm_get_safe_val('bm_icon_plus_url');
    $manual = bm_get_safe_val('bm_icon_plus_manual');

    return $url ? '<img src="' . esc_url($url) . '" alt="">' : $manual;
}

/**
 * 17. PRINT FEJLÉC
 */
function bm_get_print_header_html($is_filtered = false, $category_title = '') {
    $title = 'Árlista';
    $subtitle = $is_filtered && $category_title ? $category_title : '';

    $html  = '<div class="bm-print-header">';
    $html .= bm_get_print_logo_html();
    $html .= '<div class="bm-print-header-main">';
    $html .= '<h1 class="bm-print-title">' . esc_html($title) . '</h1>';

    if ($subtitle !== '') {
        $html .= '<div class="bm-print-subtitle">' . esc_html($subtitle) . '</div>';
    }

    $html .= '</div>';
    $html .= '</div>';

    return $html;
}

function bm_get_print_document_html($grouped, $is_filtered = false, $category_title = '') {
    $html  = '<div class="bm-print-document">';
    $html .= bm_get_print_header_html($is_filtered, $category_title);
    $html .= '<div class="bm-print-content">';

    foreach ($grouped as $cat => $items) {
        $html .= '<section class="bm-print-section">';
        $html .= '<h2 class="bm-print-section-title">' . esc_html($cat) . '</h2>';

        foreach ($items as $item) {
            $service = isset($item['szolgaltatas']) ? $item['szolgaltatas'] : '';
            $info    = isset($item['info_text']) ? $item['info_text'] : '';
            $min_ar  = isset($item['min_ar']) ? $item['min_ar'] : null;
            $max_ar  = isset($item['max_ar']) ? $item['max_ar'] : null;
            $price   = bm_format_price($min_ar, $max_ar);

            $html .= '<div class="bm-print-item">';
            $html .= '<div class="bm-print-item-main">';
            $html .= '<div class="bm-print-service-line">';
            $html .= '<span class="bm-print-service-name">' . esc_html($service) . '</span>';
            $html .= '<span class="bm-print-service-price">' . esc_html($price) . '</span>';
            $html .= '</div>';

            if (!empty($info)) {
                $html .= '<div class="bm-print-service-info">' . esc_html($info) . '</div>';
            }

            $html .= '</div>';
            $html .= '</div>';
        }

        $html .= '</section>';
    }

    $html .= '</div>';
    $html .= '<div class="bm-print-footer"><div class="bm-print-footer-left">1063 Budapest Bajnok utca 13. 1. emelet &nbsp; info@burokmedical.hu &nbsp; +36 30 713 3133</div><div class="bm-print-footer-right">Oldal <span class="bm-page-number"></span></div></div>';
    $html .= '</div>';

    return $html;
}

/**
 * 18. SHORTCODE
 */
add_shortcode('grouped_prices', function($atts) {
    $atts = shortcode_atts([
        'category' => '',
        'view'     => 'default',
        'key'      => ''
    ], $atts);

    $data = bm_get_api_data();
    $api_state = bm_get_api_state();

    if (!$data || !isset($data['response']) || !is_array($data['response'])) {
        return '<div class="bm-api-error">Az árlista jelenleg nem elérhető. Kérlek, próbáld meg később újra.</div>';
    }

    $view = strtolower(trim($atts['view']));
    $layout = get_option('bm_layout_mode', 'list');

    if (in_array($view, ['list', 'grid'], true)) {
        $layout = $view;
    }

    $is_filtered = !empty($atts['category']);
    $search_cat  = mb_strtolower(trim($atts['category']));
    $grouped     = [];

    foreach ($data['response'] as $item) {
        if (!is_array($item)) {
            continue;
        }

        // A foglalási link nélküli („Csak recepción foglalható”) tételek is
        // bekerülnek a listába – náluk a gomb helyén telefonos foglalás lesz.
        $cat = isset($item['szakterulet']) ? trim((string) $item['szakterulet']) : '';

        if ($cat === '') {
            $cat = 'Egyéb';
        }

        if ($is_filtered && mb_strtolower($cat) !== $search_cat) {
            continue;
        }

        $grouped[$cat][] = $item;
    }

    // Vizsgálat szűrő (key): egy vagy több, vesszővel elválasztott keresőszó.
    // Egy vizsgálat akkor jelenik meg, ha a neve a megadott szavak BÁRMELYIKÉT tartalmazza (VAGY logika).
    // Pl. key="botox" → csak a botox; key="botox,ínymosoly,full face" → mindhárom.
    $filter_keys = array_values(array_filter(
        array_map('trim', explode(',', mb_strtolower((string) $atts['key']))),
        function($k) { return $k !== ''; }
    ));
    if (!empty($filter_keys)) {
        foreach ($grouped as $cat => &$cat_items) {
            $cat_items = array_values(array_filter($cat_items, function($item) use ($filter_keys) {
                $name = mb_strtolower(isset($item['szolgaltatas']) ? (string) $item['szolgaltatas'] : '');
                foreach ($filter_keys as $fk) {
                    if (strpos($name, $fk) !== false) {
                        return true;
                    }
                }
                return false;
            }));
        }
        unset($cat_items);
        $grouped = array_filter($grouped, function($items) { return !empty($items); });
    }

    if (empty($grouped)) {
        return '<div class="bm-api-error">Ehhez a kategóriához jelenleg nincs megjeleníthető adat.</div>';
    }

    bm_hungarian_sort($grouped, true);
    foreach ($grouped as &$items) {
        bm_hungarian_sort($items);
    }
    unset($items);

    if ($is_filtered) {
        foreach (array_keys($grouped) as $cat_name) {
            bm_track_list_view($cat_name);
        }
    } else {
        bm_track_list_view('');
    }

    $search_icon_html = '<span class="search-icon">' . bm_get_frontend_search_icon_html() . '</span>';
    $plus_icon_html   = '<span class="plus-icon">' . bm_get_frontend_plus_icon_html() . '</span>';

    $first_category_title = $is_filtered ? array_key_first($grouped) : '';
    $out = '<div class="price-list-container bmm-arlista">';
    $out .= bm_get_print_document_html($grouped, $is_filtered, $first_category_title);

    if ($api_state['status'] === 'backup') {
        $last_sync = get_option('bm_last_sync_time');
        $out .= '<div class="bm-api-warning"><strong>Tájékoztatás:</strong> Az árlista jelenleg mentett backup adatból jelenik meg.';
        if ($last_sync) {
            $out .= ' Utolsó sikeres frissítés: <strong>' . esc_html($last_sync) . '</strong>.';
        }
        $out .= '</div>';
    }

    if (!$is_filtered) {
        $out .= '<div class="price-topbar">';
        $out .= '<div class="price-search-wrapper">';
        $out .= '<input type="text" id="price-search-input" placeholder="' . esc_attr(bm_get_safe_val('bm_search_placeholder')) . '">';
        $out .= $search_icon_html;
        $out .= '</div>';

        if (get_option('bm_show_pdf') === 'yes') {
            $out .= '<button class="bm-pdf-btn" type="button" onclick="window.print()">' . esc_html(bm_get_safe_val('bm_pdf_label')) . '</button>';
        }

        $out .= '</div>';
    }

    if ($layout === 'grid') {
        $out .= '<div class="price-grid">';

        foreach ($grouped as $cat => $items) {
            foreach ($items as $item) {
                $out .= bm_render_card($item, $cat, true);
            }
        }

        $out .= '</div>';
        $out .= '<div id="no-results-msg">' . esc_html(bm_get_safe_val('bm_no_res_txt')) . '</div>';

        if (!$is_filtered) {
            $out .= '<script>
                jQuery(document).on("keyup", "#price-search-input", function() {
                    var v = jQuery(this).val().toLowerCase();
                    var c = 0;

                    jQuery(".price-card").each(function() {
                        var m = jQuery(this).text().toLowerCase().indexOf(v) > -1;
                        jQuery(this).toggle(m);
                        if (m) c++;
                    });

                    jQuery("#no-results-msg").toggle(c === 0);
                });

                jQuery(document).on("click", ".book-btn", function(){
                    jQuery.post("' . esc_url(admin_url('admin-ajax.php')) . '", {
                        action: "bm_track_click",
                        service: jQuery(this).data("name"),
                        category: jQuery(this).data("cat")
                    });
                });
            </script>';
        } else {
            $out .= '<script>
                jQuery(document).on("click", ".book-btn", function(){
                    jQuery.post("' . esc_url(admin_url('admin-ajax.php')) . '", {
                        action: "bm_track_click",
                        service: jQuery(this).data("name"),
                        category: jQuery(this).data("cat")
                    });
                });
            </script>';
        }
    } else {
        if (!$is_filtered) {
            foreach ($grouped as $cat => $items) {
                $id = 'sec-' . sanitize_title($cat);

                $out .= '<div class="accordion-item">';
                $out .= '<div class="accordion-header" onclick="jQuery(\'#' . esc_js($id) . '\').slideToggle();jQuery(this).toggleClass(\'active\')"><h3>' . esc_html($cat) . '</h3>' . $plus_icon_html . '</div>';
                $out .= '<div id="' . esc_attr($id) . '" class="accordion-body">';

                foreach ($items as $item) {
                    $out .= bm_render_row($item, $cat, true);
                }

                $out .= '</div></div>';
            }

            $out .= '<div id="no-results-msg">' . esc_html(bm_get_safe_val('bm_no_res_txt')) . '</div>';

            $out .= '<script>
                jQuery(document).on("click", ".book-btn", function(){
                    jQuery.post("' . esc_url(admin_url('admin-ajax.php')) . '", {
                        action: "bm_track_click",
                        service: jQuery(this).data("name"),
                        category: jQuery(this).data("cat")
                    });
                });

                jQuery(document).on("keyup", "#price-search-input", function(){
                    var v = jQuery(this).val().toLowerCase();
                    var c = 0;

                    jQuery(".accordion-item").each(function(){
                        var m = false;

                        jQuery(this).find(".price-item-row").each(function(){
                            if (jQuery(this).text().toLowerCase().indexOf(v) > -1) {
                                jQuery(this).show();
                                m = true;
                            } else {
                                jQuery(this).hide();
                            }
                        });

                        if (m || jQuery(this).find("h3").text().toLowerCase().indexOf(v) > -1) {
                            jQuery(this).show();
                            c++;

                            if (v !== "") {
                                jQuery(this).find(".accordion-body").show();
                                jQuery(this).find(".accordion-header").addClass("active");
                            }
                        } else {
                            jQuery(this).hide();
                        }
                    });

                    jQuery("#no-results-msg").toggle(c === 0);
                });
            </script>';
        } else {
            // Szűrt (vizsgálat oldali) nézet: az orvosoknál használt szakterület kártya
            // (statikus fejléc + vizsgálat sorok), a leírás minden vizsgálatnál látszik.
            foreach ($grouped as $cat => $items) {
                $out .= '<div class="accordion-item bmdoc-pricelist-section bmdoc-single-open-section">';
                $out .= '<div class="accordion-header active bmdoc-static-header"><h3>' . esc_html($cat) . '</h3></div>';
                $out .= '<div class="accordion-body" style="display:block;">';

                foreach ($items as $item) {
                    $out .= bm_render_row($item, $cat, true);
                }

                $out .= '</div></div>';
            }

            $out .= '<script>
                jQuery(document).on("click", ".book-btn", function(){
                    jQuery.post("' . esc_url(admin_url('admin-ajax.php')) . '", {
                        action: "bm_track_click",
                        service: jQuery(this).data("name"),
                        category: jQuery(this).data("cat")
                    });
                });
            </script>';
        }
    }

    $out .= '</div>';

    return $out;
});

/**
 * 19. RENDER
 */
function bm_render_row($item, $cat, $show_desc = true) {
    $service = isset($item['szolgaltatas']) ? $item['szolgaltatas'] : '';
    $info    = isset($item['info_text']) ? $item['info_text'] : '';
    $link    = isset($item['link']) ? $item['link'] : '#';
    $min_ar  = isset($item['min_ar']) ? $item['min_ar'] : null;
    $max_ar  = isset($item['max_ar']) ? $item['max_ar'] : null;

    $price = bm_format_price($min_ar, $max_ar);

    // Csak recepción foglalható vizsgálatnál a leírás akkor is látszik, ha a
    // listán egyébként el van rejtve – ez mondja meg, mit takar a szolgáltatás.
    $reception_only = bm_is_reception_only($item);
    $show_info      = ($show_desc || $reception_only) && $info !== '';

    $booking = $reception_only
        ? bmm_render_phone_booking()
        : '<a href="' . esc_url($link) . '" class="book-btn" target="_blank" rel="noopener" data-name="' . esc_attr($service) . '" data-cat="' . esc_attr($cat) . '">Foglalás</a>';

    return '<div class="price-item-row' . ($reception_only ? ' bm-reception-only' : '') . '">
                <div class="service-info-box">
                    <div class="service-name">' . esc_html($service) . ($reception_only ? ' ' . bmm_render_phone_notice_icon() : '') . '</div>
                    ' . ($show_info ? '<div class="service-description">' . esc_html($info) . '</div>' : '') . '
                </div>
                <div class="price-booking-box">
                    <div class="price-pill">' . $price . '</div>
                    ' . $booking . '
                </div>
            </div>';
}

function bm_render_card($item, $cat = '', $show_desc = true) {
    $service = isset($item['szolgaltatas']) ? $item['szolgaltatas'] : '';
    $info    = isset($item['info_text']) ? $item['info_text'] : '';
    $link    = isset($item['link']) ? $item['link'] : '#';
    $min_ar  = isset($item['min_ar']) ? $item['min_ar'] : null;
    $max_ar  = isset($item['max_ar']) ? $item['max_ar'] : null;

    $price = bm_format_price($min_ar, $max_ar);

    $reception_only = bm_is_reception_only($item);
    $show_info      = ($show_desc || $reception_only) && $info !== '';

    $booking = $reception_only
        ? bmm_render_phone_booking()
        : '<a href="' . esc_url($link) . '" class="book-btn" target="_blank" rel="noopener" data-name="' . esc_attr($service) . '" data-cat="' . esc_attr($cat) . '">Foglalás</a>';

    return '<div class="price-card' . ($reception_only ? ' bm-reception-only' : '') . '">
                <div class="price-card-main">
                    <h4 class="price-card-title">' . esc_html($service) . ($reception_only ? ' ' . bmm_render_phone_notice_icon() : '') . '</h4>
                    ' . ($show_info ? '<div class="price-card-info">' . esc_html($info) . '</div>' : '') . '
                </div>
                <div class="price-card-footer">
                    <span class="price-card-price">' . $price . '</span>
                    ' . $booking . '
                </div>
            </div>';
}