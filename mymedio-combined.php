<?php
/**
 * Plugin Name: My Medio – Árlista & Orvosok
 * Description: A klinika árlistáját és orvosait jeleníti meg a weboldalon, a MyMedio rendszerből élő API-kapcsolaton keresztül – automatikus szinkronizálással és helyi mentéssel arra az esetre, ha az API épp nem elérhető. Beillesztés a [grouped_prices] (árlista) és a [doctor_list] (orvosok) shortcode-okkal; a megjelenés az admin felületen teljesen testreszabható.
 * Version: 1.4
 * Author: IWY pixel
 * Author URI: https://www.iwypixel.hu/
 * Text Domain: mymedio-combined
 *
 * FELÉPÍTÉS
 * ─────────
 * Ez a fő fájl tartalmazza a közös infrastruktúrát:
 *   - SVG MIME engedélyezés
 *   - frontend CSS és admin asset betöltés (egyszer)
 *   - közös "My Medio" admin menü és tabos felület
 *   - összevont admin értesítések
 *   - egységes "Legutóbbi szinkronizálás eredménye" státusz blokk (zöld/sárga/piros)
 *
 * A tényleges logika két modulban él (a függvénynevek prefixe különbözik, így nincs ütközés):
 *   - includes/arlista.php  → bm_*    (árlista: [grouped_prices])
 *   - includes/orvos.php    → bmdoc_* (orvosok: [doctor_list])
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* =========================================================================
 * KONSTANSOK
 * ====================================================================== */
define( 'BMM_VERSION', '1.0.0' );
define( 'BMM_FILE', __FILE__ );
define( 'BMM_PATH', plugin_dir_path( __FILE__ ) );
define( 'BMM_URL',  plugin_dir_url( __FILE__ ) );
define( 'BMM_CRON_HOOK', 'bmm_daily_sync' ); // napi automatikus szinkronizálás WP-Cron eseménye

/* =========================================================================
 * MODULOK BETÖLTÉSE
 * ====================================================================== */
require_once BMM_PATH . 'includes/arlista.php';
require_once BMM_PATH . 'includes/orvos.php';

/* =========================================================================
 * MAGYAR ÁBÉCÉ SZERINTI ÖSSZEHASONLÍTÁS (közös, mindkét modul ezt használja)
 * -------------------------------------------------------------------------
 * Két buktatót kezel:
 *
 * 1) A MyMedio adataiban több vizsgálatnév vezető vagy dupla szóközzel érkezik
 *    (pl. „ Nőgyógyászati kontrol…”, „  Gyógy,Nyak,Vállöv Masszázs (60 Perc)”).
 *    A nyers néven rendezve ezek a lista ELEJÉRE ugranak, mert a szóköz minden
 *    betűnél előrébb van. Ezért az összehasonlítás előtt normalizáljuk a szöveget.
 *
 * 2) Ha az intl kiterjesztés (Collator) nem érhető el a szerveren, a korábbi
 *    strnatcasecmp tartalék bájtsorrendben rendezett, ami magyarul hibás: az
 *    ékezetes kezdőbetűk (á, é, ő, ű …) az összes ékezet nélküli mögé kerültek
 *    („Tályogmegnyitás” a „Torok…” UTÁN). A tartalék most ékezetet normalizál.
 * ====================================================================== */

/**
 * Rendezés előtti szöveg-normalizálás: a többszörös whitespace egy szóközzé
 * olvad, a vezető/záró szóköz eltűnik.
 */
function bmm_hu_normalize( $str ) {
    return trim( preg_replace( '/\s+/u', ' ', (string) $str ) );
}

/**
 * Tartalék rendezési kulcs Collator nélküli szerverekre: kisbetűsít és az
 * ékezetes magánhangzókat az alapbetűjükre vezeti vissza, hogy azok a saját
 * betűjük mellé kerüljenek, ne a lista végére.
 */
function bmm_hu_sort_key( $str ) {
    $map = [
        'á' => 'a', 'é' => 'e', 'í' => 'i',
        'ó' => 'o', 'ö' => 'o', 'ő' => 'o',
        'ú' => 'u', 'ü' => 'u', 'ű' => 'u',
    ];

    return strtr( mb_strtolower( $str, 'UTF-8' ), $map );
}

/**
 * Magyar ábécé szerinti összehasonlítás. usort/uksort callbackként használható.
 */
function bmm_hu_compare( $a, $b ) {
    static $collator = false; // false = még nem próbáltuk létrehozni

    if ( $collator === false ) {
        $collator = class_exists( 'Collator' ) ? new Collator( 'hu_HU' ) : null;

        // A számok értékük szerint rendeződjenek („1 alkalom”, „5 alkalom”, „10 alkalom”),
        // ne karakterenként („1”, „10”, „5”).
        if ( $collator ) {
            $collator->setAttribute( Collator::NUMERIC_COLLATION, Collator::ON );
        }
    }

    $a = bmm_hu_normalize( $a );
    $b = bmm_hu_normalize( $b );

    if ( $collator ) {
        $result = $collator->compare( $a, $b );
        if ( $result !== false ) return $result;
    }

    // Tartalék: ékezet-normalizált kulcs, azonosság esetén a nyers szöveg dönt,
    // hogy a rendezés determinisztikus maradjon (pl. „a” vs „á”).
    $result = strnatcmp( bmm_hu_sort_key( $a ), bmm_hu_sort_key( $b ) );

    return $result !== 0 ? $result : strcmp( $a, $b );
}

/* =========================================================================
 * TELEFONOS FOGLALÁS („Csak recepción foglalható” vizsgálatok)
 * -------------------------------------------------------------------------
 * A MyMedio API azoknál a vizsgálatoknál, amelyeknél a foglalhatóság
 * „Csak recepción”, nem ad vissza foglalási linket (üres `link` mező).
 * Ilyenkor a foglalás gomb helyett a telefonos foglalás felirat és a
 * hívható telefonszám jelenik meg. Mindkét modul (árlista és orvosok)
 * ugyanezeket a beállításokat használja.
 * ====================================================================== */
define( 'BMM_PHONE_LABEL_DEFAULT',  'Telefonos foglalás' );
define( 'BMM_PHONE_NUMBER_DEFAULT', '+36 30 713 3133' );
define( 'BMM_PHONE_NOTICE_DEFAULT', 'A kiválasztott szolgáltatás kizárólag recepciónkon keresztül foglalható. Kérjük keresse kollégáinkat az alábbi telefonszámon: %s' );

function bmm_get_phone_label() {
    $val = trim( (string) get_option( 'bmm_phone_label', '' ) );
    return $val !== '' ? $val : BMM_PHONE_LABEL_DEFAULT;
}

function bmm_get_phone_number() {
    $val = trim( (string) get_option( 'bmm_phone_number', '' ) );
    return $val !== '' ? $val : BMM_PHONE_NUMBER_DEFAULT;
}

/**
 * A megjelenített telefonszámból tárcsázható tel: hivatkozást készít
 * (szóköz, kötőjel, zárójel nélkül, a vezető + jelet megtartva).
 */
function bmm_get_phone_href( $number = '' ) {
    $number = $number !== '' ? $number : bmm_get_phone_number();
    $plus   = ( strpos( trim( $number ), '+' ) === 0 ) ? '+' : '';

    return 'tel:' . $plus . preg_replace( '/\D/', '', $number );
}

/**
 * A vizsgálat neve mellé kerülő figyelmeztetés szövege.
 * A szövegben a %s helyére a beállított telefonszám kerül.
 */
function bmm_get_phone_notice() {
    $val = trim( (string) get_option( 'bmm_phone_notice', '' ) );
    $txt = $val !== '' ? $val : BMM_PHONE_NOTICE_DEFAULT;

    return ( strpos( $txt, '%s' ) !== false ) ? sprintf( $txt, bmm_get_phone_number() ) : $txt;
}

/**
 * A foglalás gomb helyére kerülő gomb: „Telefonos foglalás” felirat, a klinika
 * telefonszámát hívó tel: hivatkozással.
 */
function bmm_render_phone_booking() {
    $href = bmm_get_phone_href( bmm_get_phone_number() );

    return '<a class="bm-phone-link" href="' . esc_url( $href, [ 'tel' ] ) . '">' . esc_html( bmm_get_phone_label() ) . '</a>';
}

/**
 * A vizsgálat neve mellé kerülő kis körbe zárt „!” jel. A hozzá tartozó
 * magyarázat hoverre / fókuszra jelenik meg (érintőképernyőn koppintásra,
 * ezért kap tabindex-et).
 */
function bmm_render_phone_notice_icon() {
    $notice = bmm_get_phone_notice();

    return '<span class="bm-phone-notice" tabindex="0" role="note"'
         . ' aria-label="' . esc_attr( $notice ) . '"'
         . ' data-notice="' . esc_attr( $notice ) . '">!</span>';
}

/* =========================================================================
 * SVG FELTÖLTÉS ENGEDÉLYEZÉSE (egyszer)
 * ====================================================================== */
add_filter( 'upload_mimes', function( $mimes ) {
    $mimes['svg'] = 'image/svg+xml';
    return $mimes;
} );

/* =========================================================================
 * FRONTEND CSS BETÖLTÉSE (egyszer, mindkét shortcode használja)
 * ====================================================================== */
add_action( 'wp_enqueue_scripts', 'bmm_frontend_assets', 99 );

function bmm_frontend_assets() {
    if ( wp_style_is( 'bm-prices-style', 'enqueued' ) ) return;

    $path = BMM_PATH . 'css/prices.css';
    if ( file_exists( $path ) ) {
        wp_enqueue_style( 'bm-prices-style', BMM_URL . 'css/prices.css', [], filemtime( $path ) );
    }
}

/* =========================================================================
 * ADMIN ASSET BETÖLTÉS (egyszer): stílusok + médiatár + ikonfeltöltő JS
 * ====================================================================== */
add_action( 'admin_enqueue_scripts', 'bmm_admin_enqueue' );

function bmm_admin_enqueue( $hook ) {
    wp_add_inline_style( 'wp-admin', bmm_admin_inline_css() );

    if ( strpos( $hook, 'bm-mymedio' ) === false ) return;

    wp_enqueue_media();
    wp_enqueue_script( 'bmm-admin-js', BMM_URL . 'js/prices.js', [ 'jquery' ], BMM_VERSION, true );
}

/**
 * Az admin felület közös stíluskészlete (a két eredeti plugin egyesített CSS-e
 * + az összevont nézet új elemei: aloldal-fülek, státusz-lámpák, elválasztók).
 */
function bmm_admin_inline_css() {
    return '
        #toplevel_page_bm-mymedio .wp-menu-image img { width:20px !important; height:20px !important; opacity:0.85; }
        .bm-copy-btn.copied { background:#00a32a !important; border-color:#00a32a !important; color:#fff !important; }
        .bm-admin-search { margin-bottom:15px; width:100%; max-width:400px; padding:8px 12px; border:1px solid #ccd0d4; border-radius:4px; }
        .bm-row { background:#f9f9f9; padding:20px; border-radius:8px; margin-bottom:20px; display:flex; flex-wrap:wrap; gap:20px; border:1px solid #e5e5e5; align-items:flex-end; }
        .bm-unit { display:flex; flex-direction:column; gap:5px; min-width:120px; }
        .bm-section-title { background:#A57884; color:#fff; padding:10px 15px; border-radius:6px; margin-top:20px; font-weight:bold; width:100%; box-sizing:border-box; }
        .bm-icon-preview { background:#fff; padding:5px; border-radius:4px; display:flex; align-items:center; justify-content:center; width:34px; height:34px; border:1px solid #ccc; box-sizing:border-box; flex:0 0 34px; overflow:hidden; }
        .bm-icon-preview img, .bm-icon-preview svg { max-width:20px; max-height:20px; display:block; }
        .bm-status-lamp { display:inline-block; width:12px; height:12px; border-radius:50%; margin-right:10px; vertical-align:middle; }
        .bm-online { background-color:#00a32a; box-shadow:0 0 5px #00a32a; }
        .bm-offline { background-color:#d63638; box-shadow:0 0 5px #d63638; }
        .bm-icon-manual-input { flex:1; font-size:10px; min-width:220px; }
        .bm-stat-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:15px; margin-bottom:20px; }
        .bm-stat-card { background:#fff; border:1px solid #ddd; border-radius:8px; padding:18px; }
        .bm-stat-card strong { display:block; font-size:24px; margin-top:8px; color:#A57884; }
        .bm-danger-btn { background:#d63638 !important; border-color:#d63638 !important; color:#fff !important; }
        .bm-danger-btn:hover { background:#b32d2e !important; border-color:#b32d2e !important; color:#fff !important; }
        .bm-admin-alert { margin:15px 0; padding:14px 16px; border-radius:8px; border:1px solid #e5e5e5; background:#fff; }
        .bm-admin-alert-warning { border-color:#e6c15a; background:#fff8e5; }
        .bm-admin-alert-error { border-color:#d63638; background:#fff1f1; }
        .bm-admin-alert-success { border-color:#00a32a; background:#f0fcf1; }
        .bm-shortcode-card { background:#fff; border:1px solid #e3e3e3; border-radius:10px; padding:14px; margin-bottom:12px; }
        .bm-shortcode-top { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:10px; }
        .bm-shortcode-title { font-weight:700; color:#A57884; font-size:15px; }
        .bm-shortcode-switch { display:inline-flex; border:1px solid #d8d8d8; border-radius:999px; overflow:hidden; background:#fff; }
        .bm-shortcode-switch button { border:0; background:#fff; padding:8px 12px; cursor:pointer; font-size:12px; color:#444; }
        .bm-shortcode-switch button + button { border-left:1px solid #e6e6e6; }
        .bm-shortcode-switch button.active { background:#A57884; color:#fff; }
        .bm-shortcode-code-row { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .bm-shortcode-code { display:inline-block; background:#f6f7f7; border:1px solid #dcdcde; border-radius:6px; padding:10px 12px; font-family:Consolas, Monaco, monospace; font-size:13px; min-width:320px; word-break:break-all; }
        .bm-shortcode-note { margin-top:8px; font-size:12px; color:#666; }

        /* Doctor table (orvos modul admin kiegészítések) */
        .bmdoc-doctor-table { width:100%; border-collapse:collapse; margin-top:10px; }
        .bmdoc-doctor-table th { background:#f0f0f0; padding:10px 12px; text-align:left; border-bottom:2px solid #ddd; }
        .bmdoc-doctor-table td { padding:10px 12px; border-bottom:1px solid #eee; vertical-align:middle; }
        .bmdoc-badge { display:inline-block; padding:3px 10px; border-radius:999px; font-size:11px; font-weight:600; }
        .bmdoc-badge-active   { background:#d7f5e2; color:#0a4a18; }
        .bmdoc-badge-inactive { background:#f0f0f0; color:#666; }
        .bmdoc-specialty-tag { display:inline-block; background:#f3eaec; color:#A57884; border-radius:999px; padding:2px 10px; font-size:11px; margin:2px 2px 2px 0; }

        /* ── Összevont nézet: aloldal-fülek (Árlista / Orvosok) ── */
        .bmm-subtabs { display:inline-flex; gap:4px; margin:18px 0 14px; padding:4px; background:#f0e8ea; border-radius:999px; }
        .bmm-subtabs .bmm-subtab-btn { border:0; background:transparent; padding:8px 18px; cursor:pointer; font-size:13px; font-weight:600; color:#7a5b65; border-radius:999px; }
        .bmm-subtabs .bmm-subtab-btn.active { background:#A57884; color:#fff; }
        .bmm-panel-head { font-size:15px; font-weight:700; color:#A57884; margin:6px 0 12px; padding-bottom:8px; border-bottom:2px solid #f0e8ea; }
        .bmm-panel-desc { color:#666; font-size:13px; margin:0 0 14px; }
        .bmm-section-divider { color:#A57884; margin:30px 0 6px; padding-bottom:8px; border-bottom:2px solid #f0e8ea; font-size:18px; }
        .bmm-api-section { background:transparent; }

        /* ── Egységes szinkronizálási státusz blokk (zöld/sárga/piros) ── */
        .bmm-sync-block { background:#fff; border:1px solid #ccd0d4; border-radius:10px; padding:20px 22px; margin:10px 0 8px; }
        .bmm-status-row { display:flex; flex-wrap:wrap; align-items:center; gap:10px 16px; margin:10px 0; }
        .bmm-status-main { display:flex; align-items:center; gap:4px; flex:1 1 auto; min-width:260px; }
        .bmm-status-label { font-weight:700; }
        .bmm-status-detail { flex-basis:100%; font-size:12px; color:#555; padding-left:24px; }
        .bmm-status-actions { margin-left:auto; }
        .bmm-lamp { display:inline-block; width:14px; height:14px; border-radius:50%; margin-right:8px; vertical-align:middle; flex:0 0 14px; }
        .bmm-lamp-green  { background:#00a32a; box-shadow:0 0 6px #00a32a; }
        .bmm-lamp-yellow { background:#dba617; box-shadow:0 0 6px #dba617; }
        .bmm-lamp-red    { background:#d63638; box-shadow:0 0 6px #d63638; }
        .bmm-lamp-grey   { background:#9d9d9d; }

        @media (max-width:782px) {
            .bm-shortcode-code { min-width:100%; }
            .bmdoc-doctor-table { font-size:13px; }
        }
    ';
}

/* =========================================================================
 * KÖZÖS "MY MEDIO" ADMIN MENÜ
 * ====================================================================== */
add_action( 'admin_menu', 'bmm_admin_menu' );

function bmm_admin_menu() {
    $slug = 'bm-mymedio';

    add_menu_page(
        'My Medio',
        'My Medio',
        'manage_options',
        $slug,
        'bmm_admin_page_render',
        BMM_URL . 'img/mymedio_icon.svg',
        30
    );

    add_submenu_page( $slug, 'Shortcode segédlet', 'Shortcode segédlet', 'manage_options', $slug, 'bmm_admin_page_render' );
    add_submenu_page( $slug, 'Design beállítások', 'Design beállítások', 'manage_options', 'bm-mymedio-design', 'bmm_admin_page_render_settings' );
    add_submenu_page( $slug, 'Statisztika',        'Statisztika',        'manage_options', 'bm-mymedio-stats',  'bmm_admin_page_render_stats' );
    add_submenu_page( $slug, 'API beállítások',    'API beállítások',    'manage_options', 'bm-mymedio-api',    'bmm_admin_page_render_api' );
}

// A külön slugú almenük a megfelelő fülre állítják a tabot, majd a közös oldalt renderelik.
function bmm_admin_page_render_settings() { $_GET['tab'] = 'settings'; bmm_admin_page_render(); }
function bmm_admin_page_render_stats()    { $_GET['tab'] = 'stats';    bmm_admin_page_render(); }
function bmm_admin_page_render_api()      { $_GET['tab'] = 'api';      bmm_admin_page_render(); }

/* =========================================================================
 * ÖSSZEVONT ADMIN ÉRTESÍTÉSEK
 * ====================================================================== */
add_action( 'admin_notices', 'bmm_admin_notices' );

function bmm_admin_notices() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $page = isset( $_GET['page'] ) ? sanitize_text_field( $_GET['page'] ) : '';
    if ( strpos( $page, 'bm-mymedio' ) === false ) return;

    // Sikeres műveletek visszajelzése
    if ( isset( $_GET['bm_sync'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>✅ Árlista szinkronizálás kész.</p></div>';
    }
    if ( isset( $_GET['bmdoc_synced'] ) ) {
        $c = count( bmdoc_get_doctors() );
        echo '<div class="notice notice-success is-dismissible"><p>✅ Orvos szinkronizálás kész – <strong>' . (int) $c . ' orvos</strong> töltődött be. Ha ez 0, ellenőrizd az API URL-t és a tokent.</p></div>';
    }
    if ( isset( $_GET['bm_reset'] ) || isset( $_GET['bmdoc_reset'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>✅ Statisztika nullázva.</p></div>';
    }

    // Árlista API figyelmeztetések (helyi/backup adat vagy hiba)
    $a = bm_get_api_state();
    if ( $a['status'] === 'backup' ) {
        echo '<div class="notice notice-warning is-dismissible"><p><strong>My Medio – Árlista:</strong> az árlista jelenleg a helyi (mentett) adatból fut. ' . esc_html( $a['message'] ) . '</p></div>';
    } elseif ( $a['status'] === 'empty' ) {
        echo '<div class="notice notice-error is-dismissible"><p><strong>My Medio – Árlista:</strong> ' . esc_html( $a['message'] ) . '</p></div>';
    }
}

/* =========================================================================
 * KÖZÖS TABOS ADMIN OLDAL
 * ====================================================================== */
function bmm_admin_page_render() {
    $tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'help';
    if ( ! in_array( $tab, [ 'help', 'settings', 'stats', 'api' ], true ) ) {
        $tab = 'help';
    }
    ?>
    <div class="wrap" style="font-family:'Poppins', sans-serif;">
        <h1 style="color:#A57884;">My Medio – Árlista &amp; Orvosok</h1>

        <h2 class="nav-tab-wrapper">
            <a href="?page=bm-mymedio&tab=help"     class="nav-tab <?php echo $tab === 'help'     ? 'nav-tab-active' : ''; ?>">Shortcode segédlet</a>
            <a href="?page=bm-mymedio&tab=settings" class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">Design beállítások</a>
            <a href="?page=bm-mymedio&tab=stats"    class="nav-tab <?php echo $tab === 'stats'    ? 'nav-tab-active' : ''; ?>">Statisztika</a>
            <a href="?page=bm-mymedio&tab=api"      class="nav-tab <?php echo $tab === 'api'      ? 'nav-tab-active' : ''; ?>">API beállítások</a>
        </h2>

        <?php
        if ( $tab === 'help' ) {
            bmm_render_help_tab();
        } elseif ( $tab === 'settings' ) {
            bmm_render_settings_tab();
        } elseif ( $tab === 'stats' ) {
            bmm_render_stats_tab();
        } elseif ( $tab === 'api' ) {
            bmm_render_api_tab();
        }
        ?>
    </div>

    <script>
        /* Aloldal-fülek (Árlista / Orvosok) váltása – help és settings tab. */
        jQuery(document).on('click', '.bmm-subtab-btn', function () {
            var btn    = jQuery(this);
            var target = btn.data('target');
            btn.addClass('active').siblings().removeClass('active');
            btn.closest('.bmm-subtabs').nextAll('.bmm-subpanel').hide();
            jQuery('#' + target).show();
        });
    </script>
    <?php
}

/* ----------------------------------------------------------------------
 * TAB: Shortcode segédlet (Árlista key szűrővel + Orvosok key nélkül)
 * -------------------------------------------------------------------- */
function bmm_render_help_tab() {
    ?>
    <div class="bmm-subtabs">
        <button type="button" class="bmm-subtab-btn active" data-target="bmm-help-arlista">🏷️ Árlista shortcode-ok</button>
        <button type="button" class="bmm-subtab-btn" data-target="bmm-help-orvos">👩‍⚕️ Orvosok shortcode-ok</button>
    </div>

    <div id="bmm-help-arlista" class="bmm-subpanel bmm-help-panel">
        <div style="background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:8px;">
            <p class="bmm-panel-desc">Az árlista shortcode-jai. A <strong>Vizsgálat szűrő (key)</strong> mezővel egy adott vizsgálatra szűkítheted a listát.</p>
            <input type="text" class="bm-admin-search bmm-shortcode-search" placeholder="Szakterület keresés...">
            <?php bm_render_help_cards(); ?>
        </div>
    </div>

    <div id="bmm-help-orvos" class="bmm-subpanel bmm-help-panel" style="display:none;">
        <div style="background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:8px;">
            <p class="bmm-panel-desc">Az orvosokhoz tartozó shortcode-ok. (Itt nincs vizsgálat szűrő – ez a rész szándékosan key nélküli.)</p>
            <input type="text" class="bm-admin-search bmm-shortcode-search" placeholder="Szakember keresés...">
            <?php bmdoc_render_help_cards(); ?>
        </div>
    </div>

    <script>
    jQuery(function ($) {
        function buildPrice(category, view, key) {
            var sc = '[grouped_prices';
            if (category) sc += ' category="' + category + '"';
            if (key)      sc += ' key="' + key + '"';
            if (view && view !== 'default') sc += ' view="' + view + '"';
            return sc + ']';
        }
        function buildDoctor(did, view) {
            var sc = '[doctor_list';
            if (did) sc += ' doctor_id="' + did + '"';
            if (view && view !== 'default') sc += ' view="' + view + '"';
            return sc + ']';
        }
        function rebuild($card) {
            var view = $card.find('.bm-shortcode-switch button.active').data('view') || 'default';
            var sc;
            if (($card.data('type') || '') === 'doctor') {
                sc = buildDoctor($card.data('doctor') || '', view);
            } else {
                sc = buildPrice($card.data('category') || '', view, ($card.find('.bm-key-input').val() || '').trim());
            }
            $card.find('.bm-shortcode-code').text(sc);
        }

        $(document).on('click', '.bm-shortcode-switch button', function () {
            var $b = $(this);
            $b.addClass('active').siblings().removeClass('active');
            rebuild($b.closest('.bm-shortcode-card'));
        });

        $(document).on('input', '.bm-key-input', function () {
            rebuild($(this).closest('.bm-shortcode-card'));
        });

        $(document).on('click', '.bm-copy-dynamic-btn', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var text = $btn.closest('.bm-shortcode-code-row').find('.bm-shortcode-code').text();
            var orig = $btn.text();
            var $t = $('<textarea>');
            $('body').append($t);
            $t.val(text).select();
            document.execCommand('copy');
            $t.remove();
            $btn.text('Másolva!').addClass('copied');
            setTimeout(function () { $btn.text(orig).removeClass('copied'); }, 1800);
        });

        $(document).on('keyup', '.bmm-shortcode-search', function () {
            var v = $(this).val().toLowerCase();
            $(this).closest('.bmm-help-panel').find('.bm-shortcode-card').each(function () {
                if ($(this).hasClass('bm-always-show')) { $(this).show(); return; }
                $(this).toggle((($(this).data('title') || '') + '').toLowerCase().indexOf(v) > -1);
            });
        });
    });
    </script>
    <?php
}

/* ----------------------------------------------------------------------
 * TAB: Design beállítások (Árlista / Orvosok aloldalakkal elválasztva)
 * -------------------------------------------------------------------- */
function bmm_render_settings_tab() {
    ?>
    <p class="bmm-panel-desc" style="margin-top:14px;">A két bővítmény megjelenési beállításai egy helyen. Válts az <strong>Árlista</strong> és az <strong>Orvosok</strong> beállításai között a fenti gombokkal – a két rész külön menthető.</p>

    <div class="bmm-subtabs">
        <button type="button" class="bmm-subtab-btn active" data-target="bmm-set-arlista">🏷️ Árlista megjelenés</button>
        <button type="button" class="bmm-subtab-btn" data-target="bmm-set-orvos">👩‍⚕️ Orvosok megjelenés</button>
    </div>

    <div id="bmm-set-arlista" class="bmm-subpanel">
        <div class="bmm-panel-head">🏷️ Árlista ([grouped_prices]) megjelenési beállításai</div>
        <?php bm_render_design_form(); ?>
    </div>

    <div id="bmm-set-orvos" class="bmm-subpanel" style="display:none;">
        <div class="bmm-panel-head">👩‍⚕️ Orvosok ([doctor_list]) megjelenési beállításai</div>
        <?php bmdoc_render_design_form(); ?>
    </div>
    <?php
}

/* ----------------------------------------------------------------------
 * TAB: Statisztika (Árlista + Orvosok elválasztva)
 * -------------------------------------------------------------------- */
function bmm_render_stats_tab() {
    ?>
    <h2 class="bmm-section-divider">🏷️ Árlista statisztika</h2>
    <?php bm_render_stats(); ?>

    <h2 class="bmm-section-divider">👩‍⚕️ Orvosok statisztika</h2>
    <?php bmdoc_render_stats(); ?>
    <?php
}

/* ----------------------------------------------------------------------
 * TAB: API beállítások (egységes státusz blokk + két API szekció)
 * -------------------------------------------------------------------- */
function bmm_render_api_tab() {
    bmm_render_sync_status_block();
    ?>
    <h2 class="bmm-section-divider">🏷️ Árlista API – a teljes árlistához</h2>
    <p class="bmm-panel-desc" style="margin-top:0;">A <code>[grouped_prices]</code> shortcode-hoz: a klinika teljes árlistáját adja vissza (a MyMedio <code>/pricelist</code> végpontja).</p>
    <div class="bmm-api-section" style="background:#fff; padding:20px 22px; border:1px solid #ccd0d4; border-radius:8px;">
        <?php bm_render_api_form(); ?>
    </div>

    <h2 class="bmm-section-divider">👩‍⚕️ Orvosok API – az orvosok oldalához</h2>
    <p class="bmm-panel-desc" style="margin-top:0;">A <code>[doctor_list]</code> shortcode-hoz: két végpont kell – az <strong>orvoslista</strong> (kik vannak) és az <strong>orvosonkénti árlista</strong> (kinek mi az ára).</p>
    <?php bmdoc_render_api_form(); ?>
    <?php
}

/* =========================================================================
 * REQ 1 – EGYSÉGES "LEGUTÓBBI SZINKRONIZÁLÁS EREDMÉNYE" STÁTUSZ BLOKK
 *
 * Három állapotot jelez vizuálisan (zöld/sárga/piros lámpa) és szövegesen,
 * az Árlista és az Orvosok API-ra is külön sorban.
 * ====================================================================== */
function bmm_render_sync_status_block() {
    $arl = bmm_arlista_status();
    $orv = bmm_orvos_status();
    ?>
    <div class="bmm-sync-block">
        <h2 style="margin:0 0 4px;">🔄 Legutóbbi szinkronizálás eredménye</h2>
        <p style="margin:0 0 12px; color:#666;">Az API kapcsolat és az utolsó adatbetöltés aktuális állapota.</p>
        <?php
        echo bmm_status_row( 'Árlista', $arl );
        echo bmm_status_row( 'Orvosok', $orv );

        $next = wp_next_scheduled( BMM_CRON_HOOK );
        $hour = sprintf( '%02d:00', bmm_daily_sync_hour() );
        ?>
        <p style="margin:12px 0 0; padding-top:12px; border-top:1px solid #eee; color:#555; font-size:13px;">
            ⏰ <strong>Automatikus napi frissítés:</strong> minden nap <?php echo esc_html( $hour ); ?> (helyi idő).
            <?php if ( $next ) : ?>
                Következő futás: <strong><?php echo esc_html( wp_date( 'Y-m-d H:i', $next ) ); ?></strong>.
            <?php else : ?>
                <span style="color:#d63638;">Jelenleg nincs beütemezve.</span>
            <?php endif; ?>
            <br><span style="color:#888;">A frissítés a megadott idő utáni első weboldal-betöltéskor fut le (WP-Cron). Az árak emellett kb. óránként maguktól is frissülnek.</span>
        </p>
    </div>
    <?php
}

/**
 * Egyetlen státusz-sor kirajzolása (lámpa + szöveg + opcionális frissítés gomb).
 *
 * @param string $label Az integráció megnevezése.
 * @param array  $s     ['color'=>green|yellow|red|grey, 'message'=>..., 'detail'=>..., 'sync_url'=>...]
 */
function bmm_status_row( $label, $s ) {
    $alert_map = [
        'green'  => 'bm-admin-alert-success',
        'yellow' => 'bm-admin-alert-warning',
        'red'    => 'bm-admin-alert-error',
        'grey'   => '',
    ];
    $color = isset( $s['color'] ) ? $s['color'] : 'grey';
    $alert = isset( $alert_map[ $color ] ) ? $alert_map[ $color ] : '';

    $out  = '<div class="bm-admin-alert ' . esc_attr( $alert ) . ' bmm-status-row">';
    $out .= '<div class="bmm-status-main">';
    $out .= '<span class="bmm-lamp bmm-lamp-' . esc_attr( $color ) . '"></span>';
    $out .= '<span class="bmm-status-label">' . esc_html( $label ) . ':</span> ';
    $out .= '<span>' . esc_html( $s['message'] ) . '</span>';
    $out .= '</div>';

    if ( ! empty( $s['sync_url'] ) ) {
        $out .= '<div class="bmm-status-actions"><a href="' . esc_url( $s['sync_url'] ) . '" class="button button-primary" style="background:#A57884; border-color:#A57884;">Frissítés most</a></div>';
    }
    if ( ! empty( $s['detail'] ) ) {
        $out .= '<div class="bmm-status-detail">' . esc_html( $s['detail'] ) . '</div>';
    }

    $out .= '</div>';
    return $out;
}

/**
 * Árlista API állapot leképezése a 3 (+1 semleges) állapotra.
 */
function bmm_arlista_status() {
    $state    = bm_get_api_state();
    $last     = get_option( 'bm_last_sync_time' );
    $sync_url = wp_nonce_url( admin_url( 'admin.php?page=bm-mymedio&tab=api&action=bm_sync_now' ), 'bm_sync_action' );

    switch ( $state['status'] ) {
        case 'live':
            return [
                'color'    => 'green',
                'message'  => 'Az API kapcsolat aktív, az árlista élő adatból töltődött be.',
                'detail'   => $last ? ( 'Utolsó sikeres frissítés: ' . $last ) : '',
                'sync_url' => $sync_url,
            ];
        case 'backup':
            $detail = $last ? ( 'Utolsó sikeres frissítés: ' . $last ) : '';
            if ( ! empty( $state['last_error'] ) ) {
                $detail = trim( $detail . ( $detail ? ' – ' : '' ) . 'Hiba: ' . $state['last_error'] );
            }
            return [
                'color'    => 'yellow',
                'message'  => 'Az API kapcsolat nem elérhető, az árlista a helyi adatbázisból töltődött be.',
                'detail'   => $detail,
                'sync_url' => $sync_url,
            ];
        case 'empty':
        case 'none':
            return [
                'color'    => 'red',
                'message'  => 'Hiba az API kapcsolatban, nincs elérhető adat.',
                'detail'   => ! empty( $state['last_error'] ) ? $state['last_error'] : '',
                'sync_url' => $sync_url,
            ];
        default:
            return [
                'color'    => 'grey',
                'message'  => 'Még nem történt szinkronizálás.',
                'detail'   => 'Add meg az árlista API URL-t és tokent lent, majd kattints a Frissítés most gombra.',
                'sync_url' => $sync_url,
            ];
    }
}

/**
 * Orvosok API állapot leképezése a 3 (+1 semleges) állapotra.
 * Az orvoslista lekérés diagnosztikája (bmdoc_fetch_diag) + a tárolt orvosok alapján.
 */
function bmm_orvos_status() {
    $diag     = get_option( 'bmdoc_fetch_diag', [] );
    $doctors  = bmdoc_get_doctors();
    $count    = is_array( $doctors ) ? count( $doctors ) : 0;
    $last     = get_option( 'bmdoc_last_sync_global', '' );
    $sync_url = wp_nonce_url( admin_url( 'admin.php?page=bm-mymedio&tab=api&action=bmdoc_sync_all' ), 'bmdoc_sync_all_action' );

    $diag_ok  = ! empty( $diag['ok'] );
    $diag_msg = ! empty( $diag['msg'] ) ? $diag['msg'] : '';

    // 🟢 Élő: legutóbbi lekérés sikeres és vannak betöltött orvosok.
    if ( $diag_ok && $count > 0 ) {
        $detail = $last ? ( 'Utolsó sikeres frissítés: ' . $last . ' – ' . $count . ' orvos' ) : ( $count . ' orvos betöltve' );
        return [
            'color'    => 'green',
            'message'  => 'Az API kapcsolat aktív, az orvoslista élő adatból töltődött be.',
            'detail'   => $detail,
            'sync_url' => $sync_url,
        ];
    }

    // 🟡 Átmeneti: van tárolt (helyi) orvosadat, de a legutóbbi lekérés nem sikerült / üres volt.
    if ( $count > 0 ) {
        $detail = $last ? ( 'Utolsó sikeres frissítés: ' . $last ) : '';
        if ( $diag_msg ) {
            $detail = trim( $detail . ( $detail ? ' – ' : '' ) . $diag_msg );
        }
        return [
            'color'    => 'yellow',
            'message'  => 'Az API kapcsolat nem elérhető, az orvoslista a helyi adatbázisból töltődött be.',
            'detail'   => $detail,
            'sync_url' => $sync_url,
        ];
    }

    // 🔴 Hiba: nincs orvosadat és volt már (sikertelen) szinkronkísérlet vagy kritikus hiba.
    if ( ! empty( $diag ) ) {
        return [
            'color'    => 'red',
            'message'  => 'Hiba az API kapcsolatban, nincs elérhető adat.',
            'detail'   => $diag_msg,
            'sync_url' => $sync_url,
        ];
    }

    // Semleges: még nem futott szinkronizálás.
    return [
        'color'    => 'grey',
        'message'  => 'Még nem történt szinkronizálás.',
        'detail'   => 'Add meg az orvoslista API URL-t és tokent lent, majd kattints a Frissítés most gombra.',
        'sync_url' => $sync_url,
    ];
}

/* =========================================================================
 * AUTOMATIKUS NAPI SZINKRONIZÁLÁS (WP-Cron)
 *
 * Minden nap egy adott órakor (alapból 08:00, helyi időzóna) lefuttat egy
 * teljes frissítést: árlista + orvoslista + orvosonkénti árlisták.
 *
 * Az óra megváltoztatható kódból:
 *     add_filter( 'bmm_daily_sync_hour', function(){ return 6; } ); // pl. 06:00
 *
 * Megjegyzés: a WP-Cron látogatás-vezérelt – pontos 08:00-s indításhoz érdemes
 * valódi szerver-cront beállítani a wp-cron.php-ra (lásd a hozzá tartozó leírást).
 * Ha nincs ilyen, a frissítés a 08:00 utáni első oldalbetöltéskor fut le.
 * ====================================================================== */

register_activation_hook( __FILE__, 'bmm_activate' );
register_deactivation_hook( __FILE__, 'bmm_deactivate' );

function bmm_activate() {
    bmm_schedule_daily_sync();
}

function bmm_deactivate() {
    wp_clear_scheduled_hook( BMM_CRON_HOOK );
}

// Biztosítja, hogy az esemény ütemezve legyen (ha az aktiválás kimaradt, vagy az óra módosult).
add_action( 'init', 'bmm_schedule_daily_sync' );

/**
 * A napi frissítés órája (0–23), helyi időzóna szerint. Alapérték: 8.
 */
function bmm_daily_sync_hour() {
    $h = (int) apply_filters( 'bmm_daily_sync_hour', 8 );
    return max( 0, min( 23, $h ) );
}

/**
 * A következő kívánt időpont (helyi időzóna, az adott óra :00 perce) Unix timestampje.
 */
function bmm_next_daily_timestamp() {
    $tz     = wp_timezone();
    $now    = new DateTime( 'now', $tz );
    $target = new DateTime( 'today', $tz );
    $target->setTime( bmm_daily_sync_hour(), 0, 0 );
    if ( $target <= $now ) {
        $target->modify( '+1 day' );
    }
    return $target->getTimestamp();
}

/**
 * Beütemezi a napi eseményt, ha még nincs – illetve újraütemezi, ha az óra megváltozott.
 */
function bmm_schedule_daily_sync() {
    $next = wp_next_scheduled( BMM_CRON_HOOK );

    if ( $next ) {
        // Ha a beütemezett óra eltér a kívánttól, töröljük és újraütemezzük.
        $scheduled_hour = (int) wp_date( 'G', $next );
        if ( $scheduled_hour !== bmm_daily_sync_hour() ) {
            wp_clear_scheduled_hook( BMM_CRON_HOOK );
            $next = false;
        }
    }

    if ( ! $next ) {
        wp_schedule_event( bmm_next_daily_timestamp(), 'daily', BMM_CRON_HOOK );
    }
}

/**
 * A napi frissítést végrehajtó callback: minden adatforrást frissre cserél.
 */
add_action( BMM_CRON_HOOK, 'bmm_run_daily_sync' );

function bmm_run_daily_sync() {
    // 1) Árlista (teljes): cache ürítés + élő lekérés.
    if ( function_exists( 'bm_get_api_data' ) ) {
        delete_transient( 'bm_cache_v3' );
        bm_get_api_data();
    }

    // 2) Orvoslista: élő lekérés a MyMedio API-ból.
    if ( function_exists( 'bmdoc_fetch_doctors_from_api' ) ) {
        bmdoc_fetch_doctors_from_api();
    }

    // 3) Orvosonkénti árlisták: minden orvosnál cache ürítés + élő lekérés.
    if ( function_exists( 'bmdoc_get_doctors' ) && function_exists( 'bmdoc_get_doctor_api_data' ) ) {
        $pricelist_url = trim( (string) get_option( 'bmdoc_pricelist_api_url', '' ) );
        $base_url      = $pricelist_url !== '' ? $pricelist_url : trim( (string) get_option( 'bmdoc_api_url', '' ) );

        foreach ( bmdoc_get_doctors() as $doc ) {
            $did = isset( $doc['doctor_id'] ) ? (int) $doc['doctor_id'] : 0;
            if ( $did <= 0 ) {
                continue;
            }
            // A per-orvos cache kulcs az URL-ből és az ID-ból képződik – ürítsük, hogy frissen kérje le.
            if ( $base_url !== '' && function_exists( 'bmdoc_build_doctor_pricelist_url' ) ) {
                $url = bmdoc_build_doctor_pricelist_url( $base_url, $did );
                if ( $url ) {
                    delete_transient( 'bmdoc_cache_v16_' . md5( $url . '|' . $did ) );
                }
            }
            bmdoc_get_doctor_api_data( $did );
        }

        update_option( 'bmdoc_last_sync_global', current_time( 'mysql' ) );
    }
}
