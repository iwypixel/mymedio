<?php
/**
 * My Medio – ORVOS modul (a kombinált plugin része)
 * Eredetileg önálló plugin: "My Medio – Szakember Lista Pro" (v1.7).
 *
 * Funkciók: [doctor_list] shortcode, orvoslista + árlista (pricelist) API,
 * per-orvos cache/backup, design opciók (bmdoc_* prefix), statisztika.
 *
 * A közös infrastruktúra (plugin-fejléc, SVG MIME engedély, admin menü, admin
 * asset betöltés, frontend CSS betöltés, admin értesítések) a fő plugin
 * fájlban található: mymedio-combined.php
 *
 * Az admin oldal tartalmát a fő fájl tabos felülete hívja meg az alábbi
 * render függvényeken keresztül:
 *   - bmdoc_render_help_cards()  (Shortcode segédlet – Orvosok, key NÉLKÜL)
 *   - bmdoc_render_design_form() (Design beállítások – Orvosok)
 *   - bmdoc_render_stats()       (Statisztika – Orvosok)
 *   - bmdoc_render_api_form()    (API beállítások – Orvoslista + Pricelist)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* =========================================================================
 * 1. KATTINTÁS STATISZTIKA
 * ====================================================================== */
add_action( 'wp_ajax_bmdoc_track_click',        'bmdoc_track_click_handler' );
add_action( 'wp_ajax_nopriv_bmdoc_track_click', 'bmdoc_track_click_handler' );

function bmdoc_track_click_handler() {
    $service  = isset( $_POST['service'] )  ? sanitize_text_field( $_POST['service'] )  : '';
    $category = isset( $_POST['category'] ) ? sanitize_text_field( $_POST['category'] ) : 'Nincs megadva';

    if ( $service ) {
        $stats = get_option( 'bmdoc_click_stats', [] );
        if ( ! isset( $stats[ $service ] ) ) {
            $stats[ $service ] = [ 'count' => 1, 'cat' => $category ];
        } else {
            $stats[ $service ]['count'] = (int) ( $stats[ $service ]['count'] ?? 0 ) + 1;
            $stats[ $service ]['cat']   = $category;
        }
        update_option( 'bmdoc_click_stats', $stats );
    }
    wp_die();
}

/* =========================================================================
 * 2. OLDALMEGTEKINTÉS STATISZTIKA
 * ====================================================================== */
function bmdoc_track_list_view( $doctor_name = '' ) {
    static $tracked = [];
    $key = $doctor_name ? mb_strtolower( trim( $doctor_name ) ) : '__all__';
    if ( isset( $tracked[ $key ] ) ) return;
    $tracked[ $key ] = true;

    $stats = get_option( 'bmdoc_view_stats', [ 'all' => 0, 'doctors' => [] ] );
    if ( ! is_array( $stats ) ) $stats = [ 'all' => 0, 'doctors' => [] ];

    if ( $doctor_name === '' ) {
        $stats['all'] = (int) ( $stats['all'] ?? 0 ) + 1;
    } else {
        if ( ! isset( $stats['doctors'] ) || ! is_array( $stats['doctors'] ) ) $stats['doctors'] = [];
        $stats['doctors'][ $doctor_name ] = (int) ( $stats['doctors'][ $doctor_name ] ?? 0 ) + 1;
    }
    update_option( 'bmdoc_view_stats', $stats );
}

/* =========================================================================
 * 3-4. ADMIN ASSET + FRONTEND CSS
 * (Az admin stílusok/scriptek és a frontend CSS betöltése a fő plugin
 *  fájlba került, hogy egyszer töltődjenek be.)
 * ====================================================================== */

/* =========================================================================
 * 5. ALAPÉRTÉKEK
 * ====================================================================== */

/**
 * Kis segédfüggvény: az első nem-üres értéket adja vissza a felsorolt kulcsokból.
 * Több API verziót támogató mező-fallback-hez.
 *
 * @param array  $item    Az item tömb.
 * @param array  $keys    A próbálandó kulcsok listája (sorrendben).
 * @param string $default Visszatérési érték ha mind üres.
 * @return string
 */
function bmdoc_pick_field( $item, $keys, $default = '' ) {
    if ( ! is_array( $item ) ) return $default;
    foreach ( $keys as $k ) {
        if ( isset( $item[ $k ] ) ) {
            $v = trim( (string) $item[ $k ] );
            if ( $v !== '' ) return $v;
        }
    }
    return $default;
}

function bmdoc_get_fallback_value( $id ) {
    $defaults = [
        'bmdoc_font_family'       => 'Poppins',
        'bmdoc_col_primary'       => '#A57884',
        'bmdoc_col_border'        => '#9D9D9D',
        'bmdoc_cta1_bg'           => '#A57884',
        'bmdoc_cta1_txt'          => '#FFFFFF',
        'bmdoc_cta2_txt'          => '#A57884',
        'bmdoc_cta2_brd'          => '#A57884',
        'bmdoc_cta2_hover_bg'     => '#A57884',
        'bmdoc_cta2_hover_txt'    => '#FFFFFF',
        'bmdoc_no_res_txt'        => 'Nincs találat a keresésre',
        'bmdoc_no_res_size'       => '16',
        'bmdoc_no_res_col'        => '#888888',
        'bmdoc_size_h3'           => '13',
        'bmdoc_spacing'           => '10',
        'bmdoc_search_placeholder'=> 'Keresés szakember vagy szakterület nevére...',
        'bmdoc_icon_search_manual'=> '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>',
        'bmdoc_icon_plus_manual'  => '+',
        'bmdoc_icon_search_url'   => '',
        'bmdoc_icon_plus_url'     => '',
        'bmdoc_layout_mode'       => 'list',
        'bmdoc_book_btn_label'    => 'Időpontot foglalok',
        'bmdoc_group_by'          => 'szakterulet',
        'bmdoc_show_price'        => 'yes',

        // ── Betűméretek (px) ─────────────────────────────────────────────
        'bmdoc_size_doctor_name'   => '15',  // Doktor neve (grid + accordion fejléc)
        'bmdoc_size_doctor_spec'   => '11',  // Doktor szakterület alcím
        'bmdoc_size_section_label' => '16',  // SZAKTERÜLET HARMONIKA FEJLÉC (pl. „Kozmetikus")
        'bmdoc_size_subgroup'      => '11',  // Szülő-szolgáltatás belső címke
        'bmdoc_size_service'       => '14',  // Szolgáltatás név (sor)
        'bmdoc_size_info'          => '12',  // Leírás / info szöveg
        'bmdoc_size_price'         => '13',  // Ár pill
        'bmdoc_size_book_btn'      => '13',  // Foglalás gomb

        // ── Pricelist (árlista) API – külön végpont és token ─────────────
        'bmdoc_pricelist_api_url'   => '',
        'bmdoc_pricelist_api_token' => '',
    ];
    return isset( $defaults[ $id ] ) ? $defaults[ $id ] : '';
}

function bmdoc_get_safe_val( $id ) {
    $val = get_option( $id );
    return ( $val === '' || $val === null || $val === false ) ? bmdoc_get_fallback_value( $id ) : $val;
}

/* =========================================================================
 * 6. API ÁLLAPOT
 * ====================================================================== */
function bmdoc_set_api_state( $doctor_id, $state = [] ) {
    $defaults = [ 'status' => 'unknown', 'message' => '', 'last_error' => '', 'source' => '', 'checked_at' => current_time( 'mysql' ) ];
    update_option( 'bmdoc_api_state_' . (int) $doctor_id, wp_parse_args( $state, $defaults ) );
}

function bmdoc_get_api_state( $doctor_id ) {
    $state = get_option( 'bmdoc_api_state_' . (int) $doctor_id, [] );
    return wp_parse_args( $state, [ 'status' => 'unknown', 'message' => '', 'last_error' => '', 'source' => '', 'checked_at' => '' ] );
}

/**
 * MyMedio árlista URL építő.
 *
 * Az API a képernyőn látható hiba szerint doctor_id paramétert vár.
 * Helyes árlista végpont: /doctor?doctor_id=123
 * Az orvoslista végpont külön van: /doctors
 */
function bmdoc_build_doctor_pricelist_url( $base_url, $doctor_id ) {
    $base_url  = trim( (string) $base_url );
    $doctor_id = (int) $doctor_id;

    if ( $base_url === '' || $doctor_id <= 0 ) return '';

    $parts = wp_parse_url( $base_url );
    if ( ! empty( $parts['scheme'] ) && ! empty( $parts['host'] ) ) {
        $clean = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . ( $parts['path'] ?? '' );
    } else {
        $clean = preg_replace( '/\?.*$/', '', $base_url );
    }
    $clean = rtrim( $clean, '/' );

    // Árlistához mindig a /doctor végpont kell, nem /doctors.
    $clean = preg_replace( '#/doctors$#i', '/doctor', $clean );
    $clean = preg_replace( '#/doctor/\d+$#i', '/doctor', $clean );

    if ( ! preg_match( '#/doctor$#i', $clean ) ) {
        $clean .= '/doctor';
    }

    return add_query_arg( 'doctor_id', $doctor_id, $clean );
}

/**
 * Csak valódi árlista/vizsgálat rekordokat enged tovább.
 */
function bmdoc_is_valid_pricelist_item( $item ) {
    if ( ! is_array( $item ) ) return false;
    $service = bmdoc_pick_field( $item, [ 'szolgaltatas', 'service_name', 'service' ] );
    if ( $service === '' ) return false;

    // Orvoslista rekordok kizárása: ezekben általában van nev/orvos_nev, de nincs vizsgálati mező.
    $has_pricelist_field = isset( $item['szakterulet'] ) || isset( $item['szulo_szolgaltatas'] ) || isset( $item['min_ar'] ) || isset( $item['max_ar'] ) || isset( $item['link'] );
    return $has_pricelist_field;
}

/**
 * „Csak recepción foglalható” vizsgálat? A MyMedio ezeknél nem küld foglalási
 * linket, így a gomb helyett a telefonos foglalás blokkja jelenik meg.
 */
function bmdoc_is_reception_only( $item ) {
    $link = trim( (string) bmdoc_pick_field( $item, [ 'link', 'naptar_link', 'booking_url' ] ) );

    return ( $link === '' || $link === '#' || strpos( $link, 'http' ) !== 0 );
}

/**
 * Ha a végpont globális árlistát ad vissza, csak az adott orvos foglalási linkjeit engedjük át.
 * A /doctor?doctor_id=... válasznál nem minden sorban van doctor_id mező, ezért csak akkor szűrünk,
 * ha legalább egy sor egyértelműen tartalmazza az adott doctor_id-t.
 */
function bmdoc_pricelist_item_matches_doctor( $item, $doctor_id ) {
    if ( ! is_array( $item ) ) return false;
    $doctor_id = (string) (int) $doctor_id;
    if ( $doctor_id === '0' ) return false;

    foreach ( [ 'doctor_id', 'orvos_id', 'doktor_id', 'id_orvos' ] as $key ) {
        if ( isset( $item[ $key ] ) && (string) (int) $item[ $key ] === $doctor_id ) return true;
    }

    $haystack = '';
    foreach ( [ 'link', 'naptar_link', 'booking_url', 'calendar', 'naptar_script', 'naptar_script_2', 'isBookable' ] as $key ) {
        if ( isset( $item[ $key ] ) ) $haystack .= ' ' . ( is_scalar( $item[ $key ] ) ? (string) $item[ $key ] : wp_json_encode( $item[ $key ] ) );
    }

    return (bool) preg_match( '/(?:doctorId|doctor_id|doctor)=' . preg_quote( $doctor_id, '/' ) . '(?:[^0-9]|$)/i', $haystack );
}

function bmdoc_filter_pricelist_items_for_doctor( $items, $doctor_id ) {
    $items = array_values( array_filter( (array) $items, 'bmdoc_is_valid_pricelist_item' ) );
    if ( empty( $items ) ) return [];

    $matched = [];
    foreach ( $items as $item ) {
        if ( bmdoc_pricelist_item_matches_doctor( $item, $doctor_id ) ) $matched[] = $item;
    }

    // Ha globális árlista jött, lesz találat és csak azt használjuk.
    // Ha /doctor?doctor_id=... jött és nincs doctor_id/link mező a sorokban, megtartjuk az összes sort.
    return ! empty( $matched ) ? $matched : $items;
}

/* =========================================================================
 * 7. API LEKÉRÉS + CACHE + FALLBACK (egy orvoshoz)
 * ====================================================================== */
function bmdoc_get_doctor_api_data( $doctor_id ) {
    $doctor_id = (int) $doctor_id;
    if ( $doctor_id <= 0 ) return false;

    // ── Pricelist (árlista) végpont és token – ELSŐDLEGES az árak lekéréséhez ──
    // Ha nincs külön pricelist URL/token megadva, fallback a fő API beállításokra.
    $pricelist_url   = trim( (string) get_option( 'bmdoc_pricelist_api_url',   '' ) );
    $pricelist_token = trim( (string) get_option( 'bmdoc_pricelist_api_token', '' ) );

    $base_url = $pricelist_url   ?: trim( (string) get_option( 'bmdoc_api_url',   '' ) );
    $token    = $pricelist_token ?: trim( (string) get_option( 'bmdoc_api_token', '' ) );

    if ( ! $token || ! $base_url ) {
        bmdoc_set_api_state( $doctor_id, [
            'status'     => 'empty',
            'message'    => 'Az API URL vagy token nincs megadva.',
            'last_error' => 'Hiányzó API URL vagy token.',
            'source'     => 'none',
        ] );
        return false;
    }

    $url = bmdoc_build_doctor_pricelist_url( $base_url, $doctor_id );

    // Új cache-kulcs: a végpont és a verzió is benne van, hogy ne tudjon a régi,
    // hibás /doctors vagy ?doctor_id= válasz visszajönni.
    $cache_key = 'bmdoc_cache_v16_' . md5( $url . '|' . $doctor_id );
    $cached    = get_transient( $cache_key );

    if ( $cached !== false ) {
        bmdoc_set_api_state( $doctor_id, [
            'status'  => 'live',
            'message' => 'Az adatok gyorsítótárból töltődtek.',
            'source'  => 'cache',
        ] );
        return $cached;
    }

    $res = wp_remote_get( $url, [
        'headers' => [ 'Authorization' => 'Bearer ' . $token ],
        'timeout' => 15,
    ] );

    if ( ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) === 200 ) {
        $body = json_decode( wp_remote_retrieve_body( $res ), true );

        $items = [];
        if ( isset( $body['response'] ) && is_array( $body['response'] ) ) {
            $items = $body['response'];
        } elseif ( is_array( $body ) && ! empty( $body ) && isset( $body[0] ) ) {
            $items = $body;
        } elseif ( is_array( $body ) && ! empty( $body ) && ! isset( $body['error'] ) ) {
            $items = [ $body ];
        }

        if ( ! empty( $items ) ) {
            $items = bmdoc_filter_pricelist_items_for_doctor( $items, $doctor_id );
        }

        if ( ! empty( $items ) ) {
            $data = [ 'response' => $items ];
            set_transient( $cache_key, $data, 3600 );
            update_option( 'bmdoc_backup_' . $doctor_id, $data );
            update_option( 'bmdoc_last_sync_' . $doctor_id, current_time( 'mysql' ) );
            bmdoc_set_api_state( $doctor_id, [ 'status' => 'live', 'message' => 'Az API kapcsolat aktív, az adatok élő forrásból töltődtek.', 'source' => 'live' ] );
            return $data;
        }

        $backup = get_option( 'bmdoc_backup_' . $doctor_id );
        if ( $backup && isset( $backup['response'] ) && is_array( $backup['response'] ) ) {
            $backup['response'] = bmdoc_filter_pricelist_items_for_doctor( $backup['response'], $doctor_id );
            if ( ! empty( $backup['response'] ) ) {
                bmdoc_set_api_state( $doctor_id, [ 'status' => 'backup', 'message' => 'Az API válasz üres volt, mentett árlista backup adatot használunk.', 'source' => 'backup' ] );
                return $backup;
            }
        }

        bmdoc_set_api_state( $doctor_id, [ 'status' => 'empty', 'message' => 'Az API válaszolt, de nem érkezett használható adat.', 'last_error' => 'Üres response tömb.', 'source' => 'none' ] );
        return false;
    }

    $error_msg = is_wp_error( $res ) ? $res->get_error_message() : 'HTTP hiba: ' . wp_remote_retrieve_response_code( $res );
    $backup    = get_option( 'bmdoc_backup_' . $doctor_id );

    if ( $backup && isset( $backup['response'] ) && is_array( $backup['response'] ) ) {
        $backup['response'] = bmdoc_filter_pricelist_items_for_doctor( $backup['response'], $doctor_id );
        if ( ! empty( $backup['response'] ) ) {
            bmdoc_set_api_state( $doctor_id, [ 'status' => 'backup', 'message' => 'Az API nem elérhető, mentett árlista backup adatot mutatunk.', 'last_error' => $error_msg, 'source' => 'backup' ] );
            return $backup;
        }
    }

    bmdoc_set_api_state( $doctor_id, [ 'status' => 'empty', 'message' => 'Az API nem elérhető és nincs mentett adat sem.', 'last_error' => $error_msg, 'source' => 'none' ] );
    return false;
}

/* =========================================================================
 * 8. ORVOSOK KEZELÉSE (CRUD)
 * ====================================================================== */
/**
 * Két vesszővel elválasztott szakterület-lista összefésülése, duplikátumok nélkül.
 * A sorrend megmarad, az egyezést kis-nagybetűtől függetlenül vizsgáljuk.
 */
function bmdoc_merge_specialities( $a, $b ) {
    $out  = [];
    $seen = [];

    foreach ( [ $a, $b ] as $raw ) {
        if ( ! is_string( $raw ) || trim( $raw ) === '' ) continue;
        foreach ( explode( ',', $raw ) as $part ) {
            $part = trim( $part );
            if ( $part === '' ) continue;
            $key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $part, 'UTF-8' ) : strtolower( $part );
            if ( isset( $seen[ $key ] ) ) continue;
            $seen[ $key ] = true;
            $out[] = $part;
        }
    }

    return implode( ', ', $out );
}

/**
 * Duplikátumok összevonása doctor_id szerint.
 *
 * A MyMedio /doctors végpontja orvosonként annyi sort ad vissza, ahány szakvizsgája
 * (qualificationId) van, így ugyanaz az orvos többször is szerepel a válaszban.
 * Az első előfordulás a mérvadó (az aktív/inaktív kapcsoló is azt állítja), a további
 * sorokból csak a hiányzó mezőket pótoljuk, a szakterületeket pedig összefésüljük.
 */
function bmdoc_dedupe_doctors( $doctors ) {
    if ( ! is_array( $doctors ) ) return [];

    $merged = [];

    foreach ( $doctors as $doc ) {
        if ( ! is_array( $doc ) ) continue;

        $id = isset( $doc['doctor_id'] ) ? (int) $doc['doctor_id'] : 0;
        if ( $id <= 0 ) continue;

        if ( ! isset( $merged[ $id ] ) ) {
            $merged[ $id ] = $doc;
            continue;
        }

        $base = $merged[ $id ];

        // Az üresen maradt mezőket a későbbi sorokból pótoljuk.
        foreach ( [ 'nev', 'cim', 'foto', 'megjegyzes', 'naptar_script', 'naptar_script2' ] as $field ) {
            if ( empty( $base[ $field ] ) && ! empty( $doc[ $field ] ) ) {
                $base[ $field ] = $doc[ $field ];
            }
        }

        $base['szakteruletek'] = bmdoc_merge_specialities(
            isset( $base['szakteruletek'] ) ? $base['szakteruletek'] : '',
            isset( $doc['szakteruletek'] )  ? $doc['szakteruletek']  : ''
        );

        // Megjelenítési sorrendnél a legkisebb érték nyer.
        $base['sorrend'] = min(
            isset( $base['sorrend'] ) ? (int) $base['sorrend'] : 0,
            isset( $doc['sorrend'] )  ? (int) $doc['sorrend']  : 0
        );

        $merged[ $id ] = $base;
    }

    return array_values( $merged );
}

function bmdoc_get_doctors() {
    $doctors = get_option( 'bmdoc_doctors_list', [] );
    if ( ! is_array( $doctors ) || empty( $doctors ) ) return [];

    // Önjavítás: a korábbi szinkronok duplikált sorokat is elmenthettek. Ha ilyet
    // találunk, egyszer összevonjuk és vissza is írjuk, hogy ne kelljen új szinkronra várni.
    $clean = bmdoc_dedupe_doctors( $doctors );
    if ( count( $clean ) !== count( $doctors ) ) {
        update_option( 'bmdoc_doctors_list', $clean );
    }

    return $clean;
}

function bmdoc_save_doctors( $doctors ) {
    update_option( 'bmdoc_doctors_list', bmdoc_dedupe_doctors( $doctors ) );
}

function bmdoc_get_doctor_by_id( $doctor_id ) {
    foreach ( bmdoc_get_doctors() as $doc ) {
        if ( (int) $doc['doctor_id'] === (int) $doctor_id ) return $doc;
    }
    return null;
}

/* =========================================================================
 * 9. MAGYAR RENDEZÉS
 * ====================================================================== */
function bmdoc_hungarian_sort( &$array, $key_mode = false ) {
    if ( $key_mode ) {
        uksort( $array, 'bmm_hu_compare' );
    } else {
        usort( $array, function( $a, $b ) {
            // A teljes megjelenített név számít, a titulussal együtt ("Dr. …" a D betűnél).
            $va = is_array( $a ) && isset( $a['nev'] ) ? $a['nev'] : (string) $a;
            $vb = is_array( $b ) && isset( $b['nev'] ) ? $b['nev'] : (string) $b;
            return bmm_hu_compare( $va, $vb );
        } );
    }
}

/* =========================================================================
 * 10. LOGÓ (PRINT)
 * ====================================================================== */
function bmdoc_get_site_logo_url() {
    $id = get_theme_mod( 'custom_logo' );
    if ( $id ) {
        $logo = wp_get_attachment_image_src( $id, 'full' );
        if ( ! empty( $logo[0] ) ) return $logo[0];
    }
    return '';
}

/* =========================================================================
 * 11. ADMIN MENÜ
 * (A közös "My Medio" menü a fő plugin fájlban van regisztrálva.)
 * ====================================================================== */

/* =========================================================================
 * 12. BEÁLLÍTÁSOK REGISZTRÁLÁSA + ADMIN MŰVELETEK
 * ====================================================================== */
add_action( 'admin_init', 'bmdoc_admin_init' );

//
// ────────────────────────────────────────────────────────────────────────────────
// AUTOMATIKUS ORVOSLISTA FRISSÍTÉS AZ API BEÁLLÍTÁSOK VÁLTOZÁSAKOR
//
// Ha az admin a MyMedio API elérési útját vagy a hozzá tartozó tokent elmenti,
// azonnal kezdeményezzük az orvoslista lekérését a MyMedio API-ból. Ezzel
// biztosítjuk, hogy a frontenden és az adminfelületen mindig a legfrissebb
// orvosok jelenjenek meg anélkül, hogy külön szinkronizáció gombra lenne
// szükség. A következő hook-ok figyelik az opciók frissülését és egy
// callbackben meghívják a lista lekérő függvényt.
add_action( 'update_option_bmdoc_api_url', 'bmdoc_api_options_updated', 10, 2 );
add_action( 'update_option_bmdoc_api_token', 'bmdoc_api_options_updated', 10, 2 );
// Az opció első létrehozásakor is futtassuk le a frissítést
add_action( 'add_option_bmdoc_api_url', 'bmdoc_api_options_updated', 10, 2 );
add_action( 'add_option_bmdoc_api_token', 'bmdoc_api_options_updated', 10, 2 );

/**
 * API beállítás frissítésekor meghívott callback.
 *
 * Miután az API URL vagy token frissült, hívjuk meg a frissítő függvényt.
 * A paraméterek itt csupán a WordPress hook konvenciói miatt szerepelnek és nem
 * használjuk őket.
 *
 * @param mixed $old_value A korábbi opció értéke (nem használjuk).
 * @param mixed $value     Az új opció értéke (nem használjuk).
 */
function bmdoc_api_options_updated( $old_value, $value ) {
    bmdoc_fetch_doctors_from_api();
}

/**
 * Lekéri az összes orvost a MyMedio Web API-ból.
 * Részletes diagnosztikát ment el, hogy az adminban látható legyen mi történt.
 * Visszatér: [ 'ok' => bool, 'count' => int, 'http' => int, 'msg' => string, 'raw' => string ]
 */
function bmdoc_fetch_doctors_from_api() {
    $token    = get_option( 'bmdoc_api_token', '' );
    $base_url = get_option( 'bmdoc_api_url', '' );

    $diag = [
        'url'   => $base_url ? rtrim( $base_url, '/' ) : '',
        'http'  => 0,
        'ok'    => false,
        'count' => 0,
        'rows'  => 0,
        'msg'   => '',
        'raw'   => '',
        'keys'  => [],
        'time'  => current_time( 'mysql' ),
    ];

    // Ha URL vagy token hiányzik
    if ( ! $token || ! $base_url ) {
        $diag['msg'] = ! $token && ! $base_url
            ? 'Hiányzó API URL és token.'
            : ( ! $token ? 'Hiányzó API token.' : 'Hiányzó API URL.' );
        update_option( 'bmdoc_fetch_diag', $diag );
        return $diag;
    }

    $url      = rtrim( $base_url, '/' );
    // Orvoslista szinkronhoz mindig /doctors végpont kell. Ha véletlenül /doctor van beírva, javítjuk.
    $url      = preg_replace( '#/doctor$#i', '/doctors', $url );
    $url      = preg_replace( '#/doctor/\d+$#i', '/doctors', $url );
    $diag['url'] = $url;
    $response = wp_remote_get( $url, [
        'headers' => [ 'Authorization' => 'Bearer ' . $token ],
        'timeout' => 20,
    ] );

    // Hálózati hiba
    if ( is_wp_error( $response ) ) {
        $diag['msg'] = 'Hálózati hiba: ' . $response->get_error_message();
        update_option( 'bmdoc_fetch_diag', $diag );
        return $diag;
    }

    $http_code      = wp_remote_retrieve_response_code( $response );
    $raw_body       = wp_remote_retrieve_body( $response );
    $diag['http']   = $http_code;
    $diag['raw']    = mb_substr( $raw_body, 0, 800 ); // első 800 karakter diagnosztikához

    if ( $http_code !== 200 ) {
        $diag['msg'] = 'HTTP ' . $http_code . ' hiba.'
            . ( $http_code === 401 ? ' Érvénytelen token.' : '' )
            . ( $http_code === 403 ? ' Hozzáférés megtagadva.' : '' )
            . ( $http_code === 404 ? ' Az URL nem található.' : '' )
            . ( $http_code === 422 ? ' Hiányzó kötelező paraméter (pl. szakterulet_id).' : '' );
        update_option( 'bmdoc_fetch_diag', $diag );
        return $diag;
    }

    $body = json_decode( $raw_body, true );

    if ( ! is_array( $body ) ) {
        $diag['msg'] = 'Az API választ nem sikerült JSON-ként értelmezni.';
        update_option( 'bmdoc_fetch_diag', $diag );
        return $diag;
    }

    // Különböző válasz struktúrák kezelése
    if ( isset( $body['response'] ) && is_array( $body['response'] ) ) {
        $items = $body['response'];
    } elseif ( isset( $body[0] ) && is_array( $body[0] ) ) {
        $items = $body;
    } elseif ( ! empty( $body ) && ! isset( $body['error'] ) && ! isset( $body[0] ) ) {
        $items = [ $body ]; // egyetlen objektum
    } else {
        $items = [];
    }

    // Rögzítjük milyen mezőkulcsok jöttek – diagnosztikához
    if ( ! empty( $items ) && is_array( $items[0] ) ) {
        $diag['keys'] = array_keys( $items[0] );
    }

    // A kézi aktív/inaktív kapcsolót nem szabad felülírni: eltesszük a jelenlegi
    // állapotot, és a szinkron után visszaállítjuk a már ismert orvosoknál.
    $prev_active = [];
    foreach ( bmdoc_get_doctors() as $prev_doc ) {
        if ( isset( $prev_doc['doctor_id'] ) ) {
            $prev_active[ (int) $prev_doc['doctor_id'] ] = empty( $prev_doc['aktiv'] ) ? 0 : 1;
        }
    }

    $list = [];

    foreach ( $items as $item ) {
        if ( ! is_array( $item ) ) continue;

        // ID: az egyedi orvos ID a naptar_script URL-ben van elrejtve (doctorId=XXXXX)
        // Az intezmeny_id az intézmény azonosítója (minden orvosnál ugyanaz!), nem egyedi.
        $naptar_raw = isset( $item['naptar_script'] )    ? $item['naptar_script']
                    : ( isset( $item['naptar_script_2'] ) ? $item['naptar_script_2'] : '' );
        $id = 0;
        if ( $naptar_raw && preg_match( '/doctorId=(\d+)/i', $naptar_raw, $_m ) ) {
            $id = (int) $_m[1];
        }
        if ( ! $id ) {
            // FIGYELEM: a MyMedio API "doktor_id" néven küldi (K-val), ezért ez az első.
            $id = isset( $item['doktor_id'] ) ? (int) $item['doktor_id']
                : ( isset( $item['doctor_id'] ) ? (int) $item['doctor_id']
                : ( isset( $item['id'] )        ? (int) $item['id']
                : ( isset( $item['orvos_id'] )  ? (int) $item['orvos_id'] : 0 ) ) );
        }

        // Név
        $name = isset( $item['nev'] )        ? $item['nev']
              : ( isset( $item['name'] )      ? $item['name']
              : ( isset( $item['orvos_nev'] ) ? $item['orvos_nev'] : '' ) );

        // Titulus
        $title = isset( $item['cim'] )    ? $item['cim']
               : ( isset( $item['title'] ) ? $item['title'] : '' );

        // Fotó – MyMedio API: profilkep
        $photo = isset( $item['profilkep'] )      ? $item['profilkep']
               : ( isset( $item['foto'] )          ? $item['foto']
               : ( isset( $item['profile_image'] ) ? $item['profile_image'] : '' ) );

        // Leírás – MyMedio API: bemutatkozas
        $note = isset( $item['bemutatkozas'] ) ? $item['bemutatkozas']
              : ( isset( $item['megjegyzes'] )  ? $item['megjegyzes']
              : ( isset( $item['note'] )         ? $item['note'] : '' ) );

        // Sorrend
        $sorrend = isset( $item['sorrend'] ) ? (int) $item['sorrend'] : count( $list );

        // Szakterületek (vesszővel elválasztott string a MyMedio API-ból)
        $szakteruletek = isset( $item['szakteruletek'] ) ? trim( $item['szakteruletek'] ) : '';

        // Naptar script mentése (foglaláshoz)
        $naptar_script  = isset( $item['naptar_script'] )   ? $item['naptar_script']   : '';
        $naptar_script2 = isset( $item['naptar_script_2'] ) ? $item['naptar_script_2'] : '';

        if ( $id && $name ) {
            $list[] = [
                'doctor_id'      => (int) $id,
                'nev'            => $name,
                'cim'            => $title,
                'foto'           => $photo,
                'megjegyzes'     => $note,
                'szakteruletek'  => $szakteruletek,
                'naptar_script'  => $naptar_script,
                'naptar_script2' => $naptar_script2,
                'sorrend'        => $sorrend,
                // Új orvos alapból aktív; a már ismertnél megtartjuk a kézi beállítást.
                'aktiv'          => isset( $prev_active[ (int) $id ] ) ? $prev_active[ (int) $id ] : 1,
            ];
        }
    }

    // Az API szakvizsgánként külön sort ad vissza ugyanarról az orvosról – összevonjuk.
    $api_rows = count( $list );
    $list     = bmdoc_dedupe_doctors( $list );

    bmdoc_save_doctors( $list );

    $diag['ok']    = count( $list ) > 0;
    $diag['count'] = count( $list );
    $diag['rows']  = $api_rows;
    $diag['msg']   = count( $list ) > 0
        ? count( $list ) . ' orvos sikeresen beolvasva'
          . ( $api_rows > count( $list )
              ? ' (' . $api_rows . ' API sorból – ugyanaz az orvos szakvizsgánként külön sorban érkezik, ezeket összevontuk).'
              : '.' )
        : 'Az API válaszolt (HTTP 200), de egyetlen orvost sem sikerült kiolvasni. '
          . 'A kapott mezők: ' . implode( ', ', $diag['keys'] ) . '. '
          . 'Elvárt ID mező: doktor_id / doctor_id (vagy doctorId a naptar_script URL-ben). Elvárt név mező: nev.';

    update_option( 'bmdoc_fetch_diag', $diag );
    return $diag;
}

function bmdoc_admin_init() {
    // Design options
    $opts = [
        'bmdoc_font_family', 'bmdoc_col_primary', 'bmdoc_col_border', 'bmdoc_size_h3', 'bmdoc_spacing',
        'bmdoc_cta1_bg', 'bmdoc_cta1_txt', 'bmdoc_cta2_txt', 'bmdoc_cta2_brd',
        'bmdoc_cta2_hover_bg', 'bmdoc_cta2_hover_txt',
        'bmdoc_no_res_txt', 'bmdoc_no_res_size', 'bmdoc_no_res_col',
        'bmdoc_search_placeholder', 'bmdoc_icon_search_url', 'bmdoc_icon_search_manual',
        'bmdoc_icon_plus_url', 'bmdoc_icon_plus_manual',
        'bmdoc_layout_mode', 'bmdoc_book_btn_label', 'bmdoc_group_by', 'bmdoc_show_price',
        // Új betűméret beállítások
        'bmdoc_size_doctor_name', 'bmdoc_size_doctor_spec', 'bmdoc_size_section_label',
        'bmdoc_size_subgroup', 'bmdoc_size_service', 'bmdoc_size_info',
        'bmdoc_size_price', 'bmdoc_size_book_btn',
    ];
    foreach ( $opts as $opt ) {
        register_setting( 'bmdoc_design_settings_group', $opt );
    }

    register_setting( 'bmdoc_api_settings_group', 'bmdoc_api_url' );
    register_setting( 'bmdoc_api_settings_group', 'bmdoc_api_token' );
    // Pricelist (árlista) API – külön végpont és token
    register_setting( 'bmdoc_api_settings_group', 'bmdoc_pricelist_api_url' );
    register_setting( 'bmdoc_api_settings_group', 'bmdoc_pricelist_api_token' );

    // ORVOS HOZZÁADÁS
    if (
        isset( $_POST['bmdoc_add_doctor'] ) &&
        isset( $_POST['bmdoc_nonce'] ) &&
        wp_verify_nonce( sanitize_text_field( $_POST['bmdoc_nonce'] ), 'bmdoc_add_doctor' ) &&
        current_user_can( 'manage_options' )
    ) {
        $doctors   = bmdoc_get_doctors();
        $doctor_id = (int) $_POST['bmdoc_new_doctor_id'];
        $name      = sanitize_text_field( $_POST['bmdoc_new_name'] );
        $title     = sanitize_text_field( $_POST['bmdoc_new_title'] ?? '' );
        $photo     = esc_url_raw( $_POST['bmdoc_new_photo'] ?? '' );
        $note      = sanitize_textarea_field( $_POST['bmdoc_new_note'] ?? '' );
        $order     = (int) ( $_POST['bmdoc_new_order'] ?? 0 );

        if ( $doctor_id > 0 && $name ) {
            // Duplikátum ellenőrzés
            $exists = false;
            foreach ( $doctors as $doc ) {
                if ( (int) $doc['doctor_id'] === $doctor_id ) { $exists = true; break; }
            }

            if ( ! $exists ) {
                $doctors[] = [
                    'doctor_id' => $doctor_id,
                    'nev'       => $name,
                    'cim'       => $title,
                    'foto'      => $photo,
                    'megjegyzes'=> $note,
                    'sorrend'   => $order,
                    'aktiv'     => 1,
                ];
                bmdoc_save_doctors( $doctors );
            }
        }
        wp_redirect( admin_url( 'admin.php?page=bm-mymedio&tab=api&bmdoc_added=1' ) );
        exit;
    }

    // ORVOS TÖRLÉS
    if (
        isset( $_GET['bmdoc_delete'] ) &&
        isset( $_GET['bmdoc_delete_nonce'] ) &&
        wp_verify_nonce( sanitize_text_field( $_GET['bmdoc_delete_nonce'] ), 'bmdoc_delete_doctor' ) &&
        current_user_can( 'manage_options' )
    ) {
        $del_id  = (int) $_GET['bmdoc_delete'];
        $doctors = array_values( array_filter( bmdoc_get_doctors(), function( $d ) use ( $del_id ) {
            return (int) $d['doctor_id'] !== $del_id;
        } ) );
        bmdoc_save_doctors( $doctors );
        delete_transient( 'bmdoc_cache_' . $del_id );
        wp_redirect( admin_url( 'admin.php?page=bm-mymedio&tab=api&bmdoc_deleted=1' ) );
        exit;
    }

    // ORVOS STÁTUSZ VÁLTÁS
    if (
        isset( $_GET['bmdoc_toggle'] ) &&
        isset( $_GET['bmdoc_toggle_nonce'] ) &&
        wp_verify_nonce( sanitize_text_field( $_GET['bmdoc_toggle_nonce'] ), 'bmdoc_toggle_doctor' ) &&
        current_user_can( 'manage_options' )
    ) {
        $tog_id  = (int) $_GET['bmdoc_toggle'];
        $doctors = bmdoc_get_doctors();
        foreach ( $doctors as &$doc ) {
            if ( (int) $doc['doctor_id'] === $tog_id ) {
                $doc['aktiv'] = $doc['aktiv'] ? 0 : 1;
                break;
            }
        }
        unset( $doc );
        bmdoc_save_doctors( $doctors );
        wp_redirect( admin_url( 'admin.php?page=bm-mymedio&tab=api' ) );
        exit;
    }

    // GYORSÍTÓTÁR TÖRLÉS (egyetlen orvos szinkronizálása)
    if (
        isset( $_GET['action'] ) && $_GET['action'] === 'bmdoc_sync_now' &&
        isset( $_GET['bmdoc_sync_id'] ) &&
        current_user_can( 'manage_options' )
    ) {
        check_admin_referer( 'bmdoc_sync_action' );
        $sync_id = (int) $_GET['bmdoc_sync_id'];
        delete_transient( 'bmdoc_cache_' . $sync_id );
        bmdoc_get_doctor_api_data( $sync_id );
        wp_redirect( admin_url( 'admin.php?page=bm-mymedio&tab=api&bmdoc_synced=1' ) );
        exit;
    }

    // TELJES ORVOSLISTA SZINKRONIZÁLÁSA
    if (
        isset( $_GET['action'] ) && $_GET['action'] === 'bmdoc_sync_all' &&
        current_user_can( 'manage_options' )
    ) {
        check_admin_referer( 'bmdoc_sync_all_action' );
        // frissítjük a globális szinkron idejét
        update_option( 'bmdoc_last_sync_global', current_time( 'mysql' ) );
        // töröljük az esetleges globális cache-t
        delete_transient( 'bmdoc_doctors_cache' );
        // *** JAVÍTÁS: ténylegesen lekérjük az orvoslistát az API-ból ***
        bmdoc_fetch_doctors_from_api();
        wp_redirect( admin_url( 'admin.php?page=bm-mymedio&tab=api&bmdoc_synced=1' ) );
        exit;
    }

    // STATISZTIKA NULLÁZÁS
    if (
        isset( $_GET['action'] ) && $_GET['action'] === 'bmdoc_reset_stats' &&
        current_user_can( 'manage_options' )
    ) {
        check_admin_referer( 'bmdoc_reset_stats_action' );
        update_option( 'bmdoc_view_stats',  [ 'all' => 0, 'doctors' => [] ] );
        update_option( 'bmdoc_click_stats', [] );
        wp_redirect( admin_url( 'admin.php?page=bm-mymedio&tab=stats&bmdoc_reset=1' ) );
        exit;
    }
}

/* =========================================================================
 * 13. ADMIN FIGYELMEZTETÉS
 * (Az összevont admin értesítés a fő plugin fájlban van.)
 * ====================================================================== */

/* =========================================================================
 * 14. DESIGN VÁLTOZÓK + PRINT CSS FRONTEND
 * ====================================================================== */
add_action( 'wp_head', 'bmdoc_print_frontend_dynamic_styles', 99 );

function bmdoc_print_frontend_dynamic_styles() {
    // A design beallitasoknak ezen plugin kimeneten mindig ervenyesulniuk kell.
    // Nem terunk vissza akkor sem, ha masik BM style-fuggveny is letezik.

    $font = bmdoc_get_safe_val( 'bmdoc_font_family' );
    $google_fonts = [
        'Poppins', 'Roboto', 'Open Sans', 'Montserrat', 'Lato', 'Oswald', 'Raleway',
        'Playfair Display', 'Ubuntu', 'Nunito', 'Bebas Neue', 'Barlow', 'Inter',
        'Work Sans', 'DM Sans', 'Manrope', 'Mulish', 'Source Sans 3', 'Merriweather',
        'Libre Baskerville', 'Figtree', 'Plus Jakarta Sans', 'Archivo', 'Kanit',
        'Assistant', 'Quicksand', 'Rubik',
    ];

    if ( in_array( $font, $google_fonts, true ) ) {
        echo '<link href="https://fonts.googleapis.com/css2?family=' . esc_attr( str_replace( ' ', '+', $font ) ) . ':wght@300;400;500;600;700&display=swap" rel="stylesheet">';
    }

    echo '<style>
        .price-list-container.bmm-orvos{
            --bm-font-family:\'' . esc_html( $font ) . '\',sans-serif;
            --bm-accent:'        . esc_html( bmdoc_get_safe_val( 'bmdoc_col_primary' ) ) . ';
            --bm-border:'        . esc_html( bmdoc_get_safe_val( 'bmdoc_col_border' ) ) . ';
            --bm-title-size:'    . (int) bmdoc_get_safe_val( 'bmdoc_size_h3' )          . 'px;
            --bm-spacing:'       . (int) bmdoc_get_safe_val( 'bmdoc_spacing' )          . 'px;
            --bm-price-bg:'      . esc_html( bmdoc_get_safe_val( 'bmdoc_cta1_bg' ) )    . ';
            --bm-price-txt:'     . esc_html( bmdoc_get_safe_val( 'bmdoc_cta1_txt' ) )   . ';
            --bm-btn-txt:'       . esc_html( bmdoc_get_safe_val( 'bmdoc_cta2_txt' ) )   . ';
            --bm-btn-border:'    . esc_html( bmdoc_get_safe_val( 'bmdoc_cta2_brd' ) )   . ';
            --bm-btn-hover-bg:'  . esc_html( bmdoc_get_safe_val( 'bmdoc_cta2_hover_bg' ) )  . ';
            --bm-btn-hover-txt:' . esc_html( bmdoc_get_safe_val( 'bmdoc_cta2_hover_txt' ) ) . ';
            --bm-nores-col:'     . esc_html( bmdoc_get_safe_val( 'bmdoc_no_res_col' ) ) . ';
            --bm-nores-size:'    . (int) bmdoc_get_safe_val( 'bmdoc_no_res_size' )      . 'px;

            /* ── Részletes betűméret változók ── */
            --bm-doctor-name-size:'   . (int) bmdoc_get_safe_val( 'bmdoc_size_doctor_name' )   . 'px;
            --bm-doctor-spec-size:'   . (int) bmdoc_get_safe_val( 'bmdoc_size_doctor_spec' )   . 'px;
            --bm-section-label-size:' . (int) bmdoc_get_safe_val( 'bmdoc_size_section_label' ) . 'px;
            --bm-subgroup-size:'      . (int) bmdoc_get_safe_val( 'bmdoc_size_subgroup' )      . 'px;
            --bm-service-size:'       . (int) bmdoc_get_safe_val( 'bmdoc_size_service' )       . 'px;
            --bm-info-size:'          . (int) bmdoc_get_safe_val( 'bmdoc_size_info' )          . 'px;
            --bm-price-size:'         . (int) bmdoc_get_safe_val( 'bmdoc_size_price' )         . 'px;
            --bm-book-btn-size:'      . (int) bmdoc_get_safe_val( 'bmdoc_size_book_btn' )      . 'px;
        }

        .bmm-orvos .bm-api-warning{margin:0 0 18px;padding:14px 16px;border-radius:12px;border:1px solid #e6c15a;background:#fff8e5;color:#5f4b00;font-family:var(--bm-font-family);font-size:13px;line-height:1.5;}
        .bmm-orvos .bm-api-error  {margin:0 0 18px;padding:14px 16px;border-radius:12px;border:1px solid #d63638;background:#fff1f1;color:#7a1011;font-family:var(--bm-font-family);font-size:13px;line-height:1.5;}

        /* Árjegyzék-szerű megjelenés az orvos oldalakon: szakterület kártya + vizsgálat sorok */
        .price-list-container.bmm-orvos .bmdoc-pricelist-section, .price-list-container.bmm-arlista .bmdoc-pricelist-section{background:#fff;border:1px solid var(--bm-border,#9D9D9D);border-radius:24px;margin-bottom:12px;overflow:hidden;}
        .price-list-container.bmm-orvos .bmdoc-pricelist-section .accordion-header, .price-list-container.bmm-arlista .bmdoc-pricelist-section .accordion-header{border:0;border-radius:0;background:#fff;padding:16px 20px 8px;}
        .price-list-container.bmm-orvos .bmdoc-pricelist-section .bmdoc-static-header, .price-list-container.bmm-arlista .bmdoc-pricelist-section .bmdoc-static-header{cursor:default;}
        .price-list-container.bmm-orvos .bmdoc-pricelist-section .accordion-header h3, .price-list-container.bmm-arlista .bmdoc-pricelist-section .accordion-header h3{color:var(--bm-accent,#A57884)!important;font-weight:500!important;}
        .price-list-container.bmm-orvos .bmdoc-pricelist-section .accordion-body, .price-list-container.bmm-arlista .bmdoc-pricelist-section .accordion-body{padding:0 20px 10px;background:#fff;}
        .price-list-container.bmm-orvos .bmdoc-pricelist-section .price-item-row, .price-list-container.bmm-arlista .bmdoc-pricelist-section .price-item-row{border-top:1px solid rgba(0,0,0,.08);padding:15px 0;}
        .price-list-container.bmm-orvos .bmdoc-pricelist-section .price-item-row:first-child, .price-list-container.bmm-arlista .bmdoc-pricelist-section .price-item-row:first-child{border-top:0;}

        /* Orvos név (régi struktúra fallback, ha valahol használva van) */
        .bmm-orvos .bmdoc-doctor-header-name { font-size:var(--bm-doctor-name-size,15px); font-weight:600 !important; }
        .bmm-orvos .bmdoc-doctor-header-spec { font-size:var(--bm-doctor-spec-size,11px); color:#999; margin-top:2px; font-weight:400 !important; }
        .bmm-orvos .accordion-header.active .bmdoc-doctor-header-name { color:var(--bm-accent,#A57884); }

        /* SZAKTERÜLET = HARMONIKA FEJLÉC h3 (pl. „Kozmetikus", „Belgyógyászat") */
        .price-list-container.bmm-orvos .accordion-header h3 {
            font-size:var(--bm-section-label-size,16px) !important;
            font-weight:600 !important;
            margin:0 !important;
        }
        .price-list-container.bmm-orvos .accordion-header.active h3 { color:var(--bm-accent,#A57884) !important; }

        /* VIZSGÁLATOK A HARMONIKA BODY-BAN (sorok) */
        .price-list-container.bmm-orvos .bmdoc-pricelist-section .price-item-row .service-info-box .service-name,
        .price-list-container.bmm-orvos .bmdoc-pricelist-section .price-item-row .service-name,
        .price-list-container.bmm-orvos .price-item-row .service-name { font-size:var(--bm-service-size,14px) !important; font-weight:500 !important; line-height:1.35 !important; }
        .price-list-container.bmm-orvos .bmdoc-pricelist-section .price-item-row .service-info-box .service-description,
        .price-list-container.bmm-orvos .bmdoc-pricelist-section .price-item-row .service-description,
        .price-list-container.bmm-orvos .price-item-row .service-description { font-size:var(--bm-info-size,12px) !important; color:#666 !important; margin-top:2px !important; line-height:1.45 !important; }
        .price-list-container.bmm-orvos .bmdoc-pricelist-section .price-item-row .price-booking-box .price-pill,
        .price-list-container.bmm-orvos .bmdoc-pricelist-section .price-item-row .price-pill,
        .price-list-container.bmm-orvos .price-item-row .price-pill { font-size:var(--bm-price-size,13px) !important; line-height:1.2 !important; }
        .price-list-container.bmm-orvos .bmdoc-pricelist-section .price-item-row .price-booking-box .book-btn,
        .price-list-container.bmm-orvos .bmdoc-pricelist-section .price-item-row .book-btn,
        .price-list-container.bmm-orvos .price-item-row .book-btn,
        .price-list-container.bmm-orvos a.bm-phone-link[href^="tel:"] { font-size:var(--bm-book-btn-size,13px) !important; line-height:1.2 !important; }

        /* Régi szakterület-címke osztály (csak a v1.4-es backward compat) */
        .price-list-container.bmm-orvos .bmdoc-section-label {
            display:block !important;
            font-size:var(--bm-section-label-size,16px) !important;
            font-weight:700 !important;
            color:var(--bm-accent,#A57884) !important;
            margin:24px 0 10px !important;
            padding:0 0 6px !important;
            border-bottom:2px solid #f0e8ea !important;
            font-family:var(--bm-font-family,"Poppins",sans-serif) !important;
        }
        .price-list-container.bmm-orvos .bmdoc-section-group { margin-bottom:14px; }

        /* Grid kártya betűméretek */
        .bmm-orvos .bmdoc-card-doctor-name { font-size:var(--bm-doctor-name-size,15px) !important; font-weight:700 !important; color:#222 !important; margin:0 0 2px !important; }
        .bmm-orvos .bmdoc-card-doctor-spec { font-size:var(--bm-doctor-spec-size,11px) !important; color:#A57884 !important; margin:0 0 14px !important; }
        .price-list-container.bmm-orvos .price-card .price-card-title { font-size:var(--bm-service-size,14px) !important; }
        .price-list-container.bmm-orvos .price-card .price-card-info { font-size:var(--bm-info-size,12px) !important; }
        .price-list-container.bmm-orvos .price-card .price-card-price { font-size:var(--bm-price-size,13px) !important; }
        .price-list-container.bmm-orvos .price-card .book-btn { font-size:var(--bm-book-btn-size,13px) !important; }

        .bm-print-document,.bm-print-header,.bm-print-footer{display:none;}

        @media print {
            @page{margin:18mm 14mm 18mm 14mm;}
            body *{visibility:hidden !important;}
            .price-list-container,.price-list-container *{visibility:visible !important;}
            .price-list-container{position:absolute !important;left:0 !important;top:0 !important;width:100% !important;max-width:none !important;margin:0 !important;padding:0 !important;background:#fff !important;}
            .bm-print-document{display:block !important;color:#111 !important;}
            .bm-print-header{display:flex !important;flex-direction:column !important;align-items:center !important;justify-content:space-between !important;text-align:center !important;min-height:80mm !important;margin:0 0 12mm !important;}
            .bm-print-logo img{max-width:140px !important;height:auto !important;display:block !important;margin:0 auto !important;}
            .bm-print-title{font-size:24px !important;font-weight:700 !important;text-transform:uppercase !important;color:#111 !important;margin:0 !important;}
            .bm-print-section-title{font-size:14px !important;font-weight:700 !important;text-transform:uppercase !important;color:#111 !important;margin:0 0 4mm !important;padding:0 0 2mm !important;border-bottom:1px solid #cfcfcf !important;}
            .bm-print-item{display:block !important;padding:3mm 0 !important;border-bottom:1px solid #ececec !important;break-inside:avoid !important;}
            .bm-print-service-line{display:flex !important;justify-content:space-between !important;gap:10mm !important;}
            .bm-print-service-name{font-size:11px !important;font-weight:600 !important;color:#111 !important;flex:1 !important;}
            .bm-print-service-price{font-size:11px !important;font-weight:700 !important;color:#111 !important;white-space:nowrap !important;}
            .bm-print-service-info{font-size:9px !important;color:#666 !important;margin-top:1.5mm !important;}
            .bm-print-footer{display:flex !important;justify-content:space-between !important;position:fixed !important;left:0 !important;right:0 !important;bottom:0 !important;font-size:10px !important;color:#666 !important;background:#fff !important;border-top:1px solid #d9d9d9 !important;padding-top:2mm !important;}
            .bm-page-number:after{content:counter(page);}
            .price-topbar,.price-grid,.accordion-item,.price-item-row,#no-results-msg,.book-btn,.bm-phone-link,.bm-phone-notice,.bm-api-warning,.bm-api-error,.bm-corner-logo{display:none !important;}
        }
    </style>';
}

/* =========================================================================
 * 15. ÁR FORMÁZÁS
 * ====================================================================== */
function bmdoc_parse_price( $value ) {
    if ( is_int( $value ) || is_float( $value ) ) return (float) $value;
    if ( is_string( $value ) ) {
        $value = trim( $value );
        if ( $value === '' ) return null;
        $value = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
        $value = str_replace( [ "\xc2\xa0", '&nbsp;', 'Ft', 'ft', 'FT', ' ' ], '', $value );
        $value = str_replace( ',', '.', $value );
        if ( is_numeric( $value ) ) return (float) $value;
    }
    return null;
}

function bmdoc_format_price( $min, $max = null ) {
    $mn = bmdoc_parse_price( $min );
    $mx = bmdoc_parse_price( $max );
    if ( $mn === null && is_string( $min ) && trim( $min ) !== '' ) return esc_html( trim( $min ) );
    if ( $mn === null ) return 'Nincs ár megadva';
    if ( $mx !== null && $mn != $mx ) return number_format( $mn, 0, ',', '&nbsp;' ) . '–' . number_format( $mx, 0, ',', '&nbsp;' ) . ' Ft';
    return number_format( $mn, 0, ',', '&nbsp;' ) . ' Ft';
}

/* =========================================================================
 * 16. IKONOK FRONTEND
 * ====================================================================== */
function bmdoc_get_search_icon_html() {
    $url    = bmdoc_get_safe_val( 'bmdoc_icon_search_url' );
    $manual = bmdoc_get_safe_val( 'bmdoc_icon_search_manual' );
    return $url ? '<img src="' . esc_url( $url ) . '" alt="">' : $manual;
}

function bmdoc_get_plus_icon_html() {
    $url    = bmdoc_get_safe_val( 'bmdoc_icon_plus_url' );
    $manual = bmdoc_get_safe_val( 'bmdoc_icon_plus_manual' );
    return $url ? '<img src="' . esc_url( $url ) . '" alt="">' : $manual;
}

/* =========================================================================
 * 17. PRINT FEJLÉC
 * ====================================================================== */
function bmdoc_get_print_header_html( $title = 'Orvosaink' ) {
    $logo_id  = get_theme_mod( 'custom_logo' );
    $logo_html = '';
    if ( $logo_id ) {
        $logo = wp_get_attachment_image_src( $logo_id, 'full' );
        if ( ! empty( $logo[0] ) ) {
            $logo_html = '<div class="bm-print-logo"><img src="' . esc_url( $logo[0] ) . '" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '"></div>';
        }
    }
    return '<div class="bm-print-header">' . $logo_html . '<div class="bm-print-header-main"><h1 class="bm-print-title">' . esc_html( $title ) . '</h1></div></div>';
}

function bmdoc_get_print_document_html( $doctors_data ) {
    $html  = '<div class="bm-print-document">';
    $html .= bmdoc_get_print_header_html();
    $html .= '<div class="bm-print-content">';

    foreach ( $doctors_data as $doc ) {
        $html .= '<section class="bm-print-section">';
        $html .= '<h2 class="bm-print-section-title">' . esc_html( $doc['nev'] ) . ( $doc['cim'] ? ' – ' . esc_html( $doc['cim'] ) : '' ) . '</h2>';

        if ( ! empty( $doc['items'] ) ) {
            foreach ( $doc['items'] as $cat => $items ) {
                $html .= '<h3 style="font-size:11px;font-weight:700;color:#A57884;text-transform:uppercase;letter-spacing:.05em;margin:6mm 0 3mm;">' . esc_html( $cat ) . '</h3>';
                foreach ( $items as $item ) {
                    $service = $item['szolgaltatas'] ?? '';
                    $min_ar  = $item['min_ar']        ?? null;
                    $max_ar  = $item['max_ar']        ?? null;
                    $price   = bmdoc_format_price( $min_ar, $max_ar );
                    $info    = trim( (string) bmdoc_pick_field( $item, [ 'info_text', 'leiras', 'description' ] ) );
                    $html .= '<div class="bm-print-item"><div class="bm-print-item-main"><div class="bm-print-service-line"><span class="bm-print-service-name">' . esc_html( $service ) . '</span><span class="bm-print-service-price">' . esc_html( $price ) . '</span></div>';
                    if ( $info !== '' ) $html .= '<div class="bm-print-service-info">' . esc_html( $info ) . '</div>';
                    $html .= '</div></div>';
                }
            }
        }

        $html .= '</section>';
    }

    $html .= '</div>';
    $html .= '<div class="bm-print-footer"><div class="bm-print-footer-left">' . esc_html( get_bloginfo( 'name' ) ) . '</div><div class="bm-print-footer-right">Oldal <span class="bm-page-number"></span></div></div>';
    $html .= '</div>';
    return $html;
}

/* =========================================================================
 * 18. ADMIN OLDAL RENDERELÉS
 * ====================================================================== */
/**
 * SHORTCODE SEGÉDLET – Orvos kártyák (KEY szűrő NÉLKÜL).
 * A keresőmezőt, a panel-keretet és a JS-t a fő plugin fájl biztosítja.
 * A kártyák data-type="doctor" jelöléssel azonosítják magukat az egységes JS-hez.
 */
function bmdoc_render_help_cards() {
    $doctors = bmdoc_get_doctors();

    // Névsorban listázunk, mint az árlista panelnél, hogy a sok kártya
    // között gyorsan meg lehessen találni egy szakembert.
    bmdoc_hungarian_sort( $doctors );
    ?>
    <div id="bmdoc-shortcode-list" class="bmm-shortcode-list">
        <!-- Összes orvos -->
        <div class="bm-shortcode-card bm-always-show" data-type="doctor" data-doctor="" data-title="Összes orvos listája">
            <div class="bm-shortcode-top">
                <div class="bm-shortcode-title">Összes aktív szakember listája</div>
                <div class="bm-shortcode-switch">
                    <button type="button" class="active" data-view="default">Alapértelmezett</button>
                    <button type="button" data-view="list">Harmonika</button>
                    <button type="button" data-view="grid">Grid</button>
                </div>
            </div>
            <div class="bm-shortcode-code-row">
                <code class="bm-shortcode-code">[doctor_list]</code>
                <button type="button" class="button button-secondary bm-copy-dynamic-btn">Kimásolás</button>
            </div>
            <div class="bm-shortcode-note">Az összes aktív orvost listázza szakterületeikkel, áraikkal és foglalási linkjeikkel. A keresőmező alapértelmezésben ki van kapcsolva.</div>
        </div>

        <?php foreach ( $doctors as $doc ) : ?>
        <div class="bm-shortcode-card" data-type="doctor" data-title="<?php echo esc_attr( $doc['nev'] ); ?>" data-doctor="<?php echo (int) $doc['doctor_id']; ?>">
            <div class="bm-shortcode-top">
                <div class="bm-shortcode-title"><?php echo esc_html( $doc['nev'] ); ?></div>
                <div class="bm-shortcode-switch">
                    <button type="button" class="active" data-view="default">Alapértelmezett</button>
                    <button type="button" data-view="list">Harmonika</button>
                    <button type="button" data-view="grid">Grid</button>
                </div>
            </div>
            <div class="bm-shortcode-code-row">
                <code class="bm-shortcode-code">[doctor_list doctor_id="<?php echo (int) $doc['doctor_id']; ?>"]</code>
                <button type="button" class="button button-secondary bm-copy-dynamic-btn">Kimásolás</button>
            </div>
            <div class="bm-shortcode-note">Csak <?php echo esc_html( $doc['nev'] ); ?> szakterületeit és foglalásait jeleníti meg.</div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php
}

/**
 * DESIGN BEÁLLÍTÁSOK – Orvosok megjelenés (önálló űrlap, bmdoc_design_settings_group).
 */
function bmdoc_render_design_form() {
    $google_fonts = [
        'Poppins', 'Roboto', 'Open Sans', 'Montserrat', 'Lato', 'Oswald', 'Raleway',
        'Playfair Display', 'Ubuntu', 'Nunito', 'Bebas Neue', 'Barlow', 'Inter',
        'Work Sans', 'DM Sans', 'Manrope', 'Mulish', 'Source Sans 3', 'Merriweather',
        'Libre Baskerville', 'Figtree', 'Plus Jakarta Sans', 'Archivo', 'Kanit',
        'Assistant', 'Quicksand', 'Rubik', 'Arial', 'Verdana', 'Tahoma', 'Georgia',
    ];
    sort( $google_fonts );
    ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'bmdoc_design_settings_group' ); ?>

            <div class="bm-section-title">Elrendezés & Funkciók</div>
            <div class="bm-row">
                <div class="bm-unit">
                    <label>Alap nézet:</label>
                    <select name="bmdoc_layout_mode">
                        <option value="list" <?php selected( bmdoc_get_safe_val( 'bmdoc_layout_mode' ), 'list' ); ?>>Harmonika (lista)</option>
                        <option value="grid" <?php selected( bmdoc_get_safe_val( 'bmdoc_layout_mode' ), 'grid' ); ?>>Kártyás (grid)</option>
                    </select>
                </div>
                <div class="bm-unit" style="min-width:240px;">
                    <label>Csoportosítás alapja:</label>
                    <select name="bmdoc_group_by">
                        <option value="szakterulet"        <?php selected( bmdoc_get_safe_val( 'bmdoc_group_by' ), 'szakterulet' ); ?>>Szakterület (ajánlott)</option>
                        <option value="szulo_szolgaltatas" <?php selected( bmdoc_get_safe_val( 'bmdoc_group_by' ), 'szulo_szolgaltatas' ); ?>>Szülő-szolgáltatás</option>
                    </select>
                    <p style="font-size:11px;color:#666;margin:4px 0 0;">A „Szakterület” mód a gyűjtő szakterület-név alá rendezi a vizsgálatokat (pl. „Szülészet-nőgyógyászat”). Ha az item-szintű mező üres, fallbackként az adott orvos szakterületei közül az elsőt használja.</p>
                </div>
                <div class="bm-unit">
                    <label>Ár megjelenítés:</label>
                    <select name="bmdoc_show_price">
                        <option value="yes" <?php selected( bmdoc_get_safe_val( 'bmdoc_show_price' ), 'yes' ); ?>>Látható</option>
                        <option value="no"  <?php selected( bmdoc_get_safe_val( 'bmdoc_show_price' ), 'no' ); ?>>Rejtett</option>
                    </select>
                </div>
            </div>

            <div class="bm-section-title">Globális megjelenés & Ikonok</div>
            <div class="bm-row">
                <div class="bm-unit">
                    <label>Betűtípus:</label>
                    <select name="bmdoc_font_family">
                        <?php foreach ( $google_fonts as $f ) : ?>
                            <option value="<?php echo esc_attr( $f ); ?>" <?php selected( bmdoc_get_safe_val( 'bmdoc_font_family' ), $f ); ?>><?php echo esc_html( $f ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php bmdoc_icon_upload_render( 'Kereső ikon', 'bmdoc_icon_search_url', 'bmdoc_icon_search_manual' ); ?>
                <?php bmdoc_icon_upload_render( 'Plusz ikon',  'bmdoc_icon_plus_url',  'bmdoc_icon_plus_manual' ); ?>
            </div>

            <div class="bm-section-title">Harmonika & Keretek</div>
            <div class="bm-row">
                <?php bmdoc_color_input_render( 'Aktív szín',  'bmdoc_col_primary' ); ?>
                <?php bmdoc_color_input_render( 'Keret szín',  'bmdoc_col_border' ); ?>
                <div class="bm-unit">
                    <label>Cím méret (px):</label>
                    <input type="number" name="bmdoc_size_h3" value="<?php echo esc_attr( bmdoc_get_safe_val( 'bmdoc_size_h3' ) ); ?>" style="width:70px;">
                </div>
                <div class="bm-unit">
                    <label>Térköz (px):</label>
                    <input type="number" name="bmdoc_spacing" value="<?php echo esc_attr( bmdoc_get_safe_val( 'bmdoc_spacing' ) ); ?>" style="width:70px;">
                </div>
            </div>

            <div class="bm-section-title">Betűméretek (px)</div>
            <div class="bm-row">
                <div class="bm-unit">
                    <label title="A harmonika fejlécben lévő szakterület név (pl. „Kozmetikus", „Belgyógyászat")">Szakterület harmonika cím:</label>
                    <input type="number" min="8" max="32" name="bmdoc_size_section_label" value="<?php echo esc_attr( bmdoc_get_safe_val( 'bmdoc_size_section_label' ) ); ?>" style="width:70px;">
                </div>
                <div class="bm-unit">
                    <label title="Szülő-szolgáltatás belső címke (ha használt)">Alcsoport címke:</label>
                    <input type="number" min="8" max="32" name="bmdoc_size_subgroup" value="<?php echo esc_attr( bmdoc_get_safe_val( 'bmdoc_size_subgroup' ) ); ?>" style="width:70px;">
                </div>
                <div class="bm-unit">
                    <label title="A harmonikán belüli vizsgálat neve (sor, pl. „Belgyógyászati szakorvosi vizsgálat")">Vizsgálat név (sor):</label>
                    <input type="number" min="8" max="32" name="bmdoc_size_service" value="<?php echo esc_attr( bmdoc_get_safe_val( 'bmdoc_size_service' ) ); ?>" style="width:70px;">
                </div>
                <div class="bm-unit">
                    <label title="Leírás / extra info szöveg (info_text + tipus)">Leírás (info):</label>
                    <input type="number" min="8" max="24" name="bmdoc_size_info" value="<?php echo esc_attr( bmdoc_get_safe_val( 'bmdoc_size_info' ) ); ?>" style="width:70px;">
                </div>
                <div class="bm-unit">
                    <label title="Az ár pill szöveg mérete (pl. „80 000–85 000 Ft")">Ár felirat:</label>
                    <input type="number" min="8" max="32" name="bmdoc_size_price" value="<?php echo esc_attr( bmdoc_get_safe_val( 'bmdoc_size_price' ) ); ?>" style="width:70px;">
                </div>
                <div class="bm-unit">
                    <label title="„Időpontot foglalok" gomb felirat mérete">Foglalás gomb:</label>
                    <input type="number" min="8" max="32" name="bmdoc_size_book_btn" value="<?php echo esc_attr( bmdoc_get_safe_val( 'bmdoc_size_book_btn' ) ); ?>" style="width:70px;">
                </div>
            </div>

            <div class="bm-section-title">Gombok (Ár pill & Foglalás)</div>
            <div class="bm-row">
                <?php bmdoc_color_input_render( 'Ár háttér',      'bmdoc_cta1_bg' ); ?>
                <?php bmdoc_color_input_render( 'Ár szöveg',      'bmdoc_cta1_txt' ); ?>
                <?php bmdoc_color_input_render( 'Gomb szöveg',    'bmdoc_cta2_txt' ); ?>
                <?php bmdoc_color_input_render( 'Gomb keret',     'bmdoc_cta2_brd' ); ?>
                <?php bmdoc_color_input_render( 'Gomb hover BG',  'bmdoc_cta2_hover_bg' ); ?>
                <?php bmdoc_color_input_render( 'Gomb hover TXT', 'bmdoc_cta2_hover_txt' ); ?>
            </div>

            <div class="bm-section-title">Szövegek & Foglalás gomb</div>
            <div class="bm-row">
                <div class="bm-unit" style="flex:2;">
                    <label>Kereső placeholder:</label>
                    <input type="text" name="bmdoc_search_placeholder" value="<?php echo esc_attr( bmdoc_get_safe_val( 'bmdoc_search_placeholder' ) ); ?>" style="width:100%;">
                </div>
                <div class="bm-unit" style="flex:1;">
                    <label>Foglalás gomb szövege:</label>
                    <input type="text" name="bmdoc_book_btn_label" value="<?php echo esc_attr( bmdoc_get_safe_val( 'bmdoc_book_btn_label' ) ); ?>">
                </div>
                <div class="bm-unit" style="flex:2;">
                    <label>Nincs találat szöveg:</label>
                    <input type="text" name="bmdoc_no_res_txt" value="<?php echo esc_attr( bmdoc_get_safe_val( 'bmdoc_no_res_txt' ) ); ?>" style="width:100%;">
                </div>
                <?php bmdoc_color_input_render( 'Hiba szín', 'bmdoc_no_res_col' ); ?>
                <div class="bm-unit">
                    <label>Hiba méret:</label>
                    <input type="number" name="bmdoc_no_res_size" value="<?php echo esc_attr( bmdoc_get_safe_val( 'bmdoc_no_res_size' ) ); ?>" style="width:70px;">
                </div>
            </div>

            <?php submit_button( 'Orvos beállítások mentése' ); ?>
        </form>
    <?php
}

/**
 * STATISZTIKA – Orvosok (megtekintések + kattintások).
 */
function bmdoc_render_stats() {
    $view_stats  = get_option( 'bmdoc_view_stats',  [ 'all' => 0, 'doctors' => [] ] );
    $click_stats = get_option( 'bmdoc_click_stats', [] );
    ?>
        <div style="margin-top:10px; background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:8px;">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:15px; flex-wrap:wrap;">
                <h3 style="margin:0;">Megtekintési statisztika</h3>
                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=bm-mymedio&tab=stats&action=bmdoc_reset_stats' ), 'bmdoc_reset_stats_action' ) ); ?>"
                   class="button bm-danger-btn"
                   onclick="return confirm('Biztosan nullázod az orvos statisztikát?');">Orvos statisztika nullázása</a>
            </div>

            <div class="bm-stat-cards" style="margin-top:20px;">
                <div class="bm-stat-card">Összes orvos lista megtekintés<strong><?php echo (int) ( $view_stats['all'] ?? 0 ); ?></strong></div>
                <div class="bm-stat-card">Mért orvosok száma<strong><?php echo count( $view_stats['doctors'] ?? [] ); ?></strong></div>
                <div class="bm-stat-card">Összes kattintás<strong><?php echo array_sum( array_column( $click_stats, 'count' ) ); ?></strong></div>
            </div>

            <table class="wp-list-table widefat fixed striped" style="margin-bottom:30px;">
                <thead><tr><th>Orvos</th><th>Megtekintések</th></tr></thead>
                <tbody>
                <?php
                $doc_stats = $view_stats['doctors'] ?? [];
                if ( $doc_stats ) {
                    arsort( $doc_stats );
                    foreach ( $doc_stats as $name => $cnt ) echo '<tr><td>' . esc_html( $name ) . '</td><td><strong>' . (int) $cnt . '</strong></td></tr>';
                } else {
                    echo '<tr><td colspan="2">Nincs még mért megtekintés.</td></tr>';
                }
                ?>
                </tbody>
            </table>

            <h3>Kattintás-statisztika</h3>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Szakterület</th><th>Szolgáltatás</th><th>Kattintások</th></tr></thead>
                <tbody>
                <?php
                if ( ! empty( $click_stats ) ) {
                    uasort( $click_stats, function( $a, $b ) {
                        return ( is_array($b) ? (int)($b['count']??0) : (int)$b ) <=> ( is_array($a) ? (int)($a['count']??0) : (int)$a );
                    } );
                    foreach ( $click_stats as $name => $data ) {
                        $cnt = is_array($data) ? (int)($data['count']??0) : (int)$data;
                        $cat = is_array($data) ? ($data['cat']??'—') : '—';
                        echo '<tr><td>' . esc_html($cat) . '</td><td>' . esc_html($name) . '</td><td><strong>' . $cnt . '</strong></td></tr>';
                    }
                } else {
                    echo '<tr><td colspan="3">Nincs még mért kattintás.</td></tr>';
                }
                ?>
                </tbody>
            </table>
        </div>
    <?php
}

/**
 * API BEÁLLÍTÁSOK – Orvoslista (Doctors) + Árlista (Pricelist) API.
 * Részletes állapot-dobozok + diagnosztika + hitelesítő űrlapok.
 * Az összevont szinkronizálási státusz a fő plugin fájlban jelenik meg.
 */
function bmdoc_render_api_form() {
    ?>
        <div style="margin-top:10px; background:#fff; padding:25px; border:1px solid #ccd0d4; border-radius:8px;">
            <h3 style="margin-top:0;">API Kapcsolat – részletes állapot</h3>

            <?php
            // Csak a saját beállításokból olvasunk – nincs automatikus levezetés
            $token    = get_option( 'bmdoc_api_token', '' );
            $base_url = get_option( 'bmdoc_api_url', '' );
            $bmdoc_last_sync_global = get_option( 'bmdoc_last_sync_global', '' );

            // ── Kapcsolat teszt ───────────────────────────────────────────
            $status_ok  = false;
            $status_txt = '';
            $lamp_color = '#9D9D9D'; // szürke = nincs kitöltve

            if ( $token && $base_url ) {
                $test_resp = wp_remote_get( rtrim( $base_url, '/' ), [
                    'headers' => [ 'Authorization' => 'Bearer ' . $token ],
                    'timeout' => 8,
                ] );

                if ( is_wp_error( $test_resp ) ) {
                    $status_txt = 'Kapcsolódási hiba: ' . $test_resp->get_error_message();
                    $lamp_color = '#d63638';
                } elseif ( wp_remote_retrieve_response_code( $test_resp ) === 200 ) {
                    $body = json_decode( wp_remote_retrieve_body( $test_resp ), true );
                    if ( ! empty( $body ) ) {
                        $status_ok  = true;
                        $status_txt = 'Az API kapcsolat aktív, az adatok élő forrásból töltődnek.';
                        $lamp_color = '#00a32a';
                    } else {
                        $status_txt = 'Az API válaszolt (200), de az adat üres volt.';
                        $lamp_color = '#e6c15a';
                    }
                } else {
                    $http = wp_remote_retrieve_response_code( $test_resp );
                    $status_txt = 'HTTP hiba: ' . $http
                        . ( $http === 401 ? ' – Érvénytelen token.' : '' )
                        . ( $http === 404 ? ' – Az URL nem található.' : '' );
                    $lamp_color = '#d63638';
                }
            } elseif ( ! $token && ! $base_url ) {
                $status_txt = 'Kérlek, add meg az API URL-t és a tokent, majd mentsd el.';
            } elseif ( ! $token ) {
                $status_txt = 'Az API token hiányzik.';
                $lamp_color = '#e6c15a';
            } else {
                $status_txt = 'Az API URL hiányzik.';
                $lamp_color = '#e6c15a';
            }

            // ── Állapot doboz (screenshot-stílus) ────────────────────────
            $box_bg     = $status_ok ? '#f0fcf1' : ( ( $token && $base_url ) ? '#fff1f1' : '#fff8e5' );
            $box_border = $status_ok ? '#00a32a' : ( ( $token && $base_url ) ? '#d63638' : '#e6c15a' );
            $box_color  = $status_ok ? '#0a4a18' : ( ( $token && $base_url ) ? '#7a1011' : '#5f4b00' );

            // ── Pricelist API gyors állapot-vizsgálat ────────────────────
            $pl_url   = trim( (string) get_option( 'bmdoc_pricelist_api_url',   '' ) );
            $pl_token = trim( (string) get_option( 'bmdoc_pricelist_api_token', '' ) );
            $pl_status_txt = '';
            $pl_box_bg = '#fff8e5'; $pl_box_border = '#e6c15a'; $pl_box_color = '#5f4b00';

            if ( $pl_url || $pl_token ) {
                // A felhasználó beállította: ténylegesen az árlistához dedikált végpont van használva
                $pl_eff_url   = $pl_url   ?: $base_url;
                $pl_eff_token = $pl_token ?: $token;
                if ( $pl_eff_url && $pl_eff_token ) {
                    $pl_status_txt = 'Pricelist API beállítva' . ( $pl_url ? ' (külön végpont)' : ' (örökölt URL)' ) . ( $pl_token ? '' : ', a token a fő API-tól származik.' );
                    $pl_box_bg = '#f0fcf1'; $pl_box_border = '#00a32a'; $pl_box_color = '#0a4a18';
                } else {
                    $pl_status_txt = 'Pricelist API: hiányzó URL vagy token (és a fő API sem teljes).';
                    $pl_box_bg = '#fff1f1'; $pl_box_border = '#d63638'; $pl_box_color = '#7a1011';
                }
            } else {
                if ( $token && $base_url ) {
                    $pl_status_txt = 'Pricelist API nincs külön beállítva – a fő API URL/token kerül használatra az árak lekéréséhez.';
                    $pl_box_bg = '#f0f6fc'; $pl_box_border = '#72aee6'; $pl_box_color = '#1d4d8a';
                } else {
                    $pl_status_txt = 'Pricelist API nincs beállítva, és a fő API URL/token sem.';
                }
            }
            ?>

            <!-- Állapot sor: orvoslista API -->
            <div style="border:1px solid <?php echo esc_attr($box_border); ?>; background:<?php echo esc_attr($box_bg); ?>; color:<?php echo esc_attr($box_color); ?>; border-radius:8px; padding:13px 18px; margin-bottom:10px; font-size:13px;">
                <strong>Orvoslista API:</strong> <?php echo esc_html( $status_txt ); ?>
            </div>

            <!-- Állapot sor: orvosonkénti árlista API -->
            <div style="border:1px solid <?php echo esc_attr($pl_box_border); ?>; background:<?php echo esc_attr($pl_box_bg); ?>; color:<?php echo esc_attr($pl_box_color); ?>; border-radius:8px; padding:13px 18px; margin-bottom:16px; font-size:13px;">
                <strong>Orvosonkénti árlista (/doctor) API:</strong> <?php echo esc_html( $pl_status_txt ); ?>
            </div>

            <!-- Diagnosztika doboz -->
            <?php
            $diag        = get_option( 'bmdoc_fetch_diag', [] );
            $doc_count   = count( bmdoc_get_doctors() );
            if ( ! empty( $diag ) ) :
                $diag_ok  = ! empty( $diag['ok'] );
                $diag_bg  = $diag_ok ? '#f0fcf1' : ( isset($diag['http']) && $diag['http'] === 200 ? '#fff8e5' : '#fff1f1' );
                $diag_brd = $diag_ok ? '#00a32a' : ( isset($diag['http']) && $diag['http'] === 200 ? '#e6c15a' : '#d63638' );
            ?>
            <div style="border:1px solid <?php echo esc_attr($diag_brd); ?>; background:<?php echo esc_attr($diag_bg); ?>; border-radius:8px; padding:14px 18px; margin-bottom:24px; font-size:13px;">
                <strong>🔍 Legutóbbi szinkronizálás eredménye</strong>
                <table style="margin-top:8px; width:100%; border-collapse:collapse; font-size:12px;">
                    <tr>
                        <td style="padding:3px 8px 3px 0; color:#555; width:160px;">Hívott URL:</td>
                        <td><code style="word-break:break-all;"><?php echo esc_html( $diag['url'] ?? '—' ); ?></code></td>
                    </tr>
                    <tr>
                        <td style="padding:3px 8px 3px 0; color:#555;">HTTP státusz:</td>
                        <td><strong><?php echo esc_html( $diag['http'] ?? '—' ); ?></strong></td>
                    </tr>
                    <tr>
                        <td style="padding:3px 8px 3px 0; color:#555;">Eredmény:</td>
                        <td><?php echo esc_html( $diag['msg'] ?? '—' ); ?></td>
                    </tr>
                    <tr>
                        <td style="padding:3px 8px 3px 0; color:#555;">Beolvasott orvosok:</td>
                        <td><strong><?php echo (int)( $diag['count'] ?? 0 ); ?></strong> (jelenleg tárolt: <strong><?php echo $doc_count; ?></strong>)</td>
                    </tr>
                    <?php if ( ! empty( $diag['keys'] ) ) : ?>
                    <tr>
                        <td style="padding:3px 8px 3px 0; color:#555; vertical-align:top;">API mezők:</td>
                        <td><code><?php echo esc_html( implode( ', ', $diag['keys'] ) ); ?></code></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ( ! empty( $diag['raw'] ) && ( $diag['count'] ?? 0 ) === 0 ) : ?>
                    <tr>
                        <td style="padding:6px 8px 3px 0; color:#555; vertical-align:top;">API válasz (részlet):</td>
                        <td><pre style="margin:0; font-size:11px; background:#f6f7f7; padding:8px; border-radius:4px; overflow-x:auto; max-height:120px; white-space:pre-wrap;"><?php echo esc_html( $diag['raw'] ); ?></pre></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
            <?php endif; ?>

            <!-- API URL és Token form -->
            <form method="post" action="options.php">
                <?php settings_fields( 'bmdoc_api_settings_group' ); ?>

                <h3 style="margin-top:24px; padding-bottom:8px; border-bottom:2px solid #f0e8ea; color:#A57884;">📋 1. Orvoslista – kik jelenjenek meg</h3>
                <p style="color:#666; font-size:12px; margin-bottom:8px;">Az orvosok listáját tölti be (név, titulus, fotó, naptár). A MyMedio <code>/doctors</code> végpontja.</p>
                <table class="form-table">
                    <tr>
                        <th style="width:160px;">Orvoslista URL</th>
                        <td>
                            <input type="text" name="bmdoc_api_url"
                                   value="<?php echo esc_attr( get_option( 'bmdoc_api_url', '' ) ); ?>"
                                   class="regular-text" style="width:100%;"
                                   placeholder="pl. https://app.kvery.io/query/api/SAJAT_TOKEN/v1.0.1/doctors">
                            <p class="description">A MyMedio orvoslista végpontja – <code>/doctors</code> (többes szám) a végén.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Orvoslista token</th>
                        <td>
                            <input type="password" name="bmdoc_api_token"
                                   value="<?php echo esc_attr( get_option( 'bmdoc_api_token', '' ) ); ?>"
                                   class="regular-text" style="width:100%;"
                                   autocomplete="new-password"
                                   placeholder="Add meg az API tokenedet">
                            <p class="description">A MyMedio fiókodhoz tartozó egyedi API token.</p>
                        </td>
                    </tr>
                </table>

                <h3 style="margin-top:32px; padding-bottom:8px; border-bottom:2px solid #f0e8ea; color:#A57884;">💰 2. Orvosonkénti árlista – kinek mi az ára</h3>
                <p style="color:#666; font-size:12px; margin-bottom:8px;">
                    Az egyes orvosokhoz tartozó vizsgálatokat és árakat tölti be a <code>[doctor_list]</code>-hez.<br>
                    <strong>A legegyszerűbb, ha mindkét mezőt ÜRESEN hagyod</strong> – ekkor a rendszer a fenti orvoslista beállításból automatikusan a helyes <code>/doctor?doctor_id=…</code> végpontot képzi, és örökli a tokent is.<br>
                    Csak akkor töltsd ki, ha külön végpontot vagy tokent használsz az árakhoz. Ilyenkor a <code>/doctor</code> (<strong>egyes szám!</strong>) végpont az ajánlott – <strong>ne</strong> a <code>/doctors</code>.
                </p>
                <table class="form-table">
                    <tr>
                        <th style="width:160px;">Árlista URL <span style="font-weight:400;color:#888;">(opcionális)</span></th>
                        <td>
                            <input type="text" name="bmdoc_pricelist_api_url"
                                   value="<?php echo esc_attr( get_option( 'bmdoc_pricelist_api_url', '' ) ); ?>"
                                   class="regular-text" style="width:100%;"
                                   placeholder="Hagyd üresen, vagy pl. https://app.kvery.io/query/api/SAJAT_TOKEN/v1.0.1/doctor">
                            <p class="description">Hagyd üresen, ha ugyanaz, mint az orvoslistánál. Ha kitöltöd: a <code>/doctor</code> (egyes szám) végpont az ajánlott.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Árlista token <span style="font-weight:400;color:#888;">(opcionális)</span></th>
                        <td>
                            <input type="password" name="bmdoc_pricelist_api_token"
                                   value="<?php echo esc_attr( get_option( 'bmdoc_pricelist_api_token', '' ) ); ?>"
                                   class="regular-text" style="width:100%;"
                                   autocomplete="new-password"
                                   placeholder="Hagyd üresen, ha ugyanaz a token, mint fent">
                            <p class="description">Hagyd üresen, ha ugyanaz a token, mint az orvoslistánál.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( 'Orvos API mentése' ); ?>
            </form>

        </div>
    <?php
}

/* =========================================================================
 * 19. ADMIN SEGÉDFÜGGVÉNYEK
 * ====================================================================== */
function bmdoc_color_input_render( $label, $id ) {
    $value = bmdoc_get_safe_val( $id );
    echo "<div class='bm-unit'>
            <label>" . esc_html($label) . ":</label>
            <div style='display:flex;gap:5px;'>
                <input type='text' id='" . esc_attr($id) . "_hex' name='" . esc_attr($id) . "' value='" . esc_attr($value) . "' style='width:75px;font-size:11px;'>
                <input type='color' value='" . esc_attr($value) . "' oninput='document.getElementById(\"" . esc_js($id) . "_hex\").value=this.value.toUpperCase()'>
            </div>
          </div>";
}

function bmdoc_icon_upload_render( $label, $id_url, $id_manual ) {
    $url        = bmdoc_get_safe_val( $id_url );
    $manual     = bmdoc_get_safe_val( $id_manual );
    $preview_id = $id_url . '_prev';
    $preview    = $url ? "<img src='" . esc_url($url) . "' alt=''>" : $manual;

    echo "<div class='bm-unit' style='flex:1; min-width:320px;'>
            <label>" . esc_html($label) . " (SVG kód vagy feltöltés):</label>
            <div style='display:flex; gap:10px; align-items:center;'>
                <div class='bm-icon-preview' id='" . esc_attr($preview_id) . "'>" . $preview . "</div>
                <input type='text' class='bm-icon-manual-input' name='" . esc_attr($id_manual) . "' value='" . esc_attr($manual) . "' placeholder='SVG kód...'
                       oninput='document.getElementById(\"" . esc_js($preview_id) . "\").innerHTML = this.value || \"+\";'>
                <input type='hidden' name='" . esc_attr($id_url) . "' id='" . esc_attr($id_url) . "' value='" . esc_url($url) . "'>
                <button type='button' class='button bm-upload-btn' data-input='" . esc_attr($id_url) . "' data-preview='" . esc_attr($preview_id) . "'>Feltöltés</button>
            </div>
          </div>";
}

/* =========================================================================
 * 20. SHORTCODE – [doctor_list] / [doctor_list doctor_id="13"]
 *
 * Paraméterek:
 *   doctor_id  – opcionális, csak egy orvost mutat
 *   view       – "list" | "grid"
 *   search     – "yes" | "no"
 * ====================================================================== */
add_shortcode( 'doctor_list', 'bmdoc_shortcode_render' );

/**
 * A MyMedio orvos végpontja (/doctor?doctor_id=…) több vizsgálatnál üres info_text-et
 * ad, miközben az árlista végpontja ugyanahhoz a vizsgálathoz tartalmaz leírást.
 * Ez a térkép (szakterület|vizsgálatnév → leírás) az árlista adataiból pótolja a hiányt.
 */
function bmdoc_get_pricelist_info_map() {
    static $map = null;
    if ( $map !== null ) return $map;

    $map  = [];
    $data = function_exists( 'bm_get_api_data' ) ? bm_get_api_data() : false;
    if ( ! $data || empty( $data['response'] ) || ! is_array( $data['response'] ) ) return $map;

    foreach ( $data['response'] as $pl_item ) {
        if ( ! is_array( $pl_item ) ) continue;
        $pl_info = trim( (string) ( $pl_item['info_text'] ?? '' ) );
        if ( $pl_info === '' ) continue;
        $pl_key = mb_strtolower( bmm_hu_normalize( ( $pl_item['szakterulet'] ?? '' ) . '|' . ( $pl_item['szolgaltatas'] ?? '' ) ) );
        if ( ! isset( $map[ $pl_key ] ) ) $map[ $pl_key ] = $pl_info;
    }

    return $map;
}

function bmdoc_shortcode_render( $atts ) {
    $atts = shortcode_atts( [
        'doctor_id' => '',
        'view'      => '',
        'search'    => 'no',
    ], $atts );

    $layout      = bmdoc_get_safe_val( 'bmdoc_layout_mode' );
    $group_key   = bmdoc_get_safe_val( 'bmdoc_group_by' );
    $book_label  = bmdoc_get_safe_val( 'bmdoc_book_btn_label' );
    $no_res      = bmdoc_get_safe_val( 'bmdoc_no_res_txt' );
    $show_price  = bmdoc_get_safe_val( 'bmdoc_show_price' ) !== 'no';
    $show_search = strtolower( trim( $atts['search'] ) ) === 'yes';

    if ( in_array( strtolower($atts['view']), ['list','grid'], true ) ) {
        $layout = strtolower( $atts['view'] );
    }

    // Orvosok listája
    $all_doctors = bmdoc_get_doctors();
    if ( empty( $all_doctors ) ) {
        return '<div class="bm-api-error">Még nincsenek szakemberek hozzáadva. Kérlek, add hozzá az adminban: My Medio → Szakember Lista → Szakemberek kezelése.</div>';
    }

    // Egy orvos szűrése ha meg van adva
    if ( $atts['doctor_id'] !== '' ) {
        $filter_id   = (int) $atts['doctor_id'];
        $all_doctors = array_filter( $all_doctors, function($d) use ($filter_id) {
            return (int) $d['doctor_id'] === $filter_id;
        } );
    }

    // Csak aktív orvosok
    $all_doctors = array_filter( $all_doctors, function($d) { return ! empty($d['aktiv']); } );

    if ( empty( $all_doctors ) ) {
        return '<div class="bm-api-error">Nincs aktív szakember, vagy a megadott doctor_id nem található.</div>';
    }

    // Sorrend
    usort( $all_doctors, function($a,$b){ return (int)($a['sorrend']??0) <=> (int)($b['sorrend']??0); } );

    // API adatok lekérése minden orvoshoz
    $doctors_data = [];
    $any_backup   = false;

    foreach ( $all_doctors as $doc ) {
        $did  = (int) $doc['doctor_id'];
        $data = bmdoc_get_doctor_api_data( $did );

        if ( ! $data || ! isset( $data['response'] ) ) continue;

        // Csoportosítás
        // Ha a szolgáltatás szintű szulo_szolgaltatas/szakterulet üres,
        // a doktor szintű szakteruletek mezőből (vesszővel elválasztva) az elsőt vesszük.
        $doc_spec_primary = '';
        if ( ! empty( $doc['szakteruletek'] ) ) {
            $spec_parts = explode( ',', $doc['szakteruletek'] );
            $doc_spec_primary = trim( $spec_parts[0] );
        }

        $grouped = [];
        $seen_services = [];
        foreach ( $data['response'] as $item ) {
            if ( ! is_array($item) ) continue;

            // ── HARMONIKA FEJLÉC: kizárólag szakterület lehet. ──────────────
            // Nem használunk szulo_szolgaltatas-t csoportnak, mert abból lettek
            // vizsgálat-nevű harmonika fejlécek. Ha nincs szakterület, a sort kihagyjuk.
            $group = bmdoc_pick_field( $item, [ 'szakterulet', 'specialty', 'category' ] );
            if ( $group === '' ) continue;

            // ── Vizsgálatnév: kizárólag valódi szolgáltatás/vizsgálat mezőből. ──────
            $service_name = bmdoc_pick_field( $item, [ 'szolgaltatas', 'service_name', 'service' ] );
            if ( $service_name === '' ) continue;

            // Ha a vizsgálatnév véletlenül megegyezik a szakterülettel, azt nem tesszük ki külön sorként.
            if ( mb_strtolower( trim( $service_name ) ) === mb_strtolower( trim( $group ) ) ) continue;

            // Foglalhatóság szűrés: a "Nem foglalható sehol" tételek nem kerülnek az
            // árlistába. A "Csak recepción" foglalhatóak viszont igen – náluk a
            // foglalás gomb helyett a telefonos foglalás blokkja jelenik meg.
            $fogl = bmdoc_pick_field( $item, [ 'foglalhatosag', 'foglalas_mod', 'booking_mode', 'foglalas_tipus' ] );
            if ( $fogl !== '' ) {
                $fogl_n = mb_strtolower( trim( $fogl ) );
                if ( strpos( $fogl_n, 'nem foglalható' ) !== false ) continue;
            }

            // Normalizáljuk a kulcsot, hogy a render egységesen tudja használni
            $item['szolgaltatas'] = $service_name;

            // Ha az orvos végpont nem ad leírást, az árlista végpont leírása kerül a helyére.
            if ( trim( (string) bmdoc_pick_field( $item, [ 'info_text', 'leiras', 'description' ] ) ) === '' ) {
                $pl_info_map = bmdoc_get_pricelist_info_map();
                $pl_info_key = mb_strtolower( bmm_hu_normalize( $group . '|' . $service_name ) );
                if ( isset( $pl_info_map[ $pl_info_key ] ) ) $item['info_text'] = $pl_info_map[ $pl_info_key ];
            }

            // Deduplikálás kizárólag szakterület + vizsgálatnév alapján, hogy ugyanaz a
            // vizsgálat ne jelenjen meg többször akkor sem, ha az API eltérő foglalási
            // linkkel vagy árral adja vissza (pl. Dr. Tóth László esete). Az első
            // előfordulást tartjuk meg, annak árával és foglalási linkjével.
            $dedupe_key = mb_strtolower( trim( $group . '|' . $service_name ) );
            if ( isset( $seen_services[ $dedupe_key ] ) ) continue;
            $seen_services[ $dedupe_key ] = true;

            $grouped[$group][] = $item;
        }

        if ( empty($grouped) ) continue;

        // Vizsgálatok ABC sorrendbe rendezése MINDEN szakterületen belül,
        // hogy a vizsgálatnevek (pl. „Prof. Dr. Birtalan Iván Ph.D.") helyes
        // betűrendben jelenjenek meg a harmonika body-ban és a grid kártyákon.
        foreach ( $grouped as &$group_items ) {
            usort( $group_items, function( $a, $b ) {
                $na = isset( $a['szolgaltatas'] ) ? (string) $a['szolgaltatas'] : '';
                $nb = isset( $b['szolgaltatas'] ) ? (string) $b['szolgaltatas'] : '';
                return bmm_hu_compare( $na, $nb );
            } );
        }
        unset( $group_items );

        // Szakterületek (harmonika fejlécek) ABC sorrendbe rendezése.
        uksort( $grouped, 'bmm_hu_compare' );

        $state = bmdoc_get_api_state( $did );
        if ( $state['source'] === 'backup' ) $any_backup = true;

        $doctors_data[] = array_merge( $doc, [ 'items' => $grouped ] );

        bmdoc_track_list_view( $doc['nev'] );
    }

    if ( empty($doctors_data) ) {
        return '<div class="bm-api-error">Az API jelenleg nem elérhető, és nincs mentett adat. Kérlek, próbáld meg később.</div>';
    }

    $search_icon = '<span class="search-icon">' . bmdoc_get_search_icon_html() . '</span>';
    $plus_icon   = '<span class="plus-icon">' . bmdoc_get_plus_icon_html() . '</span>';

    $last_sync_global = get_option( 'bmdoc_last_sync_' . (int)($doctors_data[0]['doctor_id']??0) );

    ob_start();
    ?>
    <div class="price-list-container bmm-orvos">

        <?php echo bmdoc_get_print_document_html( $doctors_data ); ?>

        <?php if (false && $any_backup) : ?>
        <div class="bm-api-warning">
            <strong>Tájékoztatás:</strong> Egyes adatok jelenleg mentett backup forrásból jelennek meg.
            <?php if ($last_sync_global) echo ' Utolsó sikeres frissítés: <strong>' . esc_html($last_sync_global) . '</strong>.'; ?>
        </div>
        <?php endif; ?>

        <?php if ($show_search) : ?>
        <div class="price-topbar">
            <div class="price-search-wrapper">
                <input type="text" id="bmdoc-search-input" placeholder="<?php echo esc_attr(bmdoc_get_safe_val('bmdoc_search_placeholder')); ?>">
                <?php echo $search_icon; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($layout === 'grid') : ?>
        <!-- ─────────── GRID NÉZET ─────────── -->
        <div class="price-grid">
            <?php foreach ($doctors_data as $doc) :
                $photo = $doc['foto'] ?? '';
            ?>
            <?php foreach ($doc['items'] as $cat => $items) : ?>
                <?php foreach ($items as $item) : ?>
                <?php $reception_only = bmdoc_is_reception_only( $item ); ?>
                <div class="price-card<?php echo $reception_only ? ' bm-reception-only' : ''; ?>">
                    <div class="price-card-main">
                        <?php if ($photo) : ?>
                        <div style="margin-bottom:12px;">
                            <img src="<?php echo esc_url($photo); ?>" alt="<?php echo esc_attr($doc['nev']); ?>"
                                 style="width:52px;height:52px;border-radius:50%;object-fit:cover;border:2px solid var(--bm-accent,#A57884);">
                        </div>
                        <?php endif; ?>
                        <p class="bmdoc-card-doctor-name"><?php echo esc_html(($doc['cim']?' '.$doc['cim'].' ':'')) . esc_html($doc['nev']); ?></p>
                        <p class="bmdoc-card-doctor-spec"><?php echo esc_html($cat); ?></p>
                        <h4 class="price-card-title"><?php echo esc_html($item['szolgaltatas']??''); ?><?php if ($reception_only) echo ' ' . bmm_render_phone_notice_icon(); ?></h4>
                        <?php $card_info = trim( (string) bmdoc_pick_field( $item, [ 'info_text', 'leiras', 'description' ] ) ); ?>
                        <?php if ( $card_info !== '' ) : ?>
                        <div class="price-card-info"><?php echo esc_html( $card_info ); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="price-card-footer">
                        <?php if ($show_price) : ?>
                        <span class="price-card-price"><?php echo bmdoc_format_price($item['min_ar']??null, $item['max_ar']??null); ?></span>
                        <?php endif; ?>
                        <?php if ($reception_only) : ?>
                        <?php echo bmm_render_phone_booking(); ?>
                        <?php else : ?>
                        <a href="<?php echo esc_url($item['link']??'#'); ?>"
                           class="book-btn" target="_blank" rel="noopener"
                           data-name="<?php echo esc_attr($item['szolgaltatas']??''); ?>"
                           data-cat="<?php echo esc_attr($doc['nev']); ?>"><?php echo esc_html($book_label); ?></a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <?php endforeach; ?>
        </div>

        <?php else : ?>
        <!-- ─────────── LISTA / HARMONIKA NÉZET ─────────── -->
        <!--
            Hierarchia:
              1) SZAKTERÜLET (kategória, pl. „Kozmetikus", „Belgyógyászat") = HARMONIKA
                 – fejléc: szakterület neve (kattintható, +/- ikonnal)
              2) Body (kinyitva): a szakterülethez tartozó vizsgálatok listája
                   – Vizsgálat neve | Ár (min–max Ft) | Időpontot foglalok gomb
                   – opcionális leírás (info_text + tipus)
        -->
        <?php
        // Összegyűjtjük az összes szakterület → szolgáltatás párt az összes orvosból.
        // (Egy orvos esetén ez egyszerűen az ő szakterületei lesznek.)
        $all_groups = []; // [ 'Szakterület' => [ ['items'=>[], 'doc'=>$doc], ... ] ]

        foreach ( $doctors_data as $doc ) {
            foreach ( $doc['items'] as $cat => $items ) {
                $all_groups[ $cat ][] = [
                    'items' => $items,
                    'doc'   => $doc,
                ];
            }
        }

        // Magyar rendezés a szakterületek között
        uksort( $all_groups, 'bmm_hu_compare' );

        $acc_index = 0;
        $single_group_mode = ( count( $all_groups ) === 1 );
        // Egy konkrét orvos oldalán (doctor_id megadva) MINDEN szakterület harmonikája
        // legyen nyitva – pl. ha az orvosnak két szakterülete van, mindkettő látszódjon.
        // A teljes (összes orvos) listán marad az alapértelmezett: csak az első nyitva.
        $open_all = ( trim( (string) $atts['doctor_id'] ) !== '' );

        foreach ( $all_groups as $cat => $group_entries ) :
            $is_open = ( $open_all || $acc_index === 0 );
            $acc_id  = 'bmdoc-cat-' . $acc_index++;
        ?>
        <div class="accordion-item bmdoc-pricelist-section <?php echo $single_group_mode ? 'bmdoc-single-open-section' : ''; ?>">
            <?php if ( $single_group_mode ) : ?>
                <div class="accordion-header active bmdoc-static-header">
                    <h3><?php echo esc_html( $cat ); ?></h3>
                </div>
            <?php else : ?>
                <div class="accordion-header <?php echo $is_open ? 'active' : ''; ?>" onclick="jQuery('#<?php echo esc_js($acc_id); ?>').slideToggle(); jQuery(this).toggleClass('active');">
                    <h3><?php echo esc_html( $cat ); ?></h3>
                    <?php echo $plus_icon; ?>
                </div>
            <?php endif; ?>

            <div id="<?php echo esc_attr($acc_id); ?>" class="accordion-body" style="<?php echo ( $single_group_mode || $is_open ) ? 'display:block;' : ''; ?>">
                <?php
                // Egy szakterületen belül több orvos vizsgálatai is szerepelhetnek.
                // Ezeket EGYÜTT, közös ABC sorrendben jelenítjük meg, hogy a lista ne
                // induljon újra betűrendben orvosonként (a foglalási link sorszinten marad).
                // Ugyanazt a vizsgálatot (név alapján) csak EGYSZER mutatjuk – ha több
                // orvos is kínálja, az első előfordulás árát és foglalási linkjét tartjuk meg.
                $cat_rows = [];
                $cat_seen = [];
                foreach ( $group_entries as $entry ) {
                    $entry_doc = $entry['doc'];
                    foreach ( $entry['items'] as $entry_item ) {
                        $svc_name = isset( $entry_item['szolgaltatas'] ) ? (string) $entry_item['szolgaltatas'] : '';
                        $svc_key  = mb_strtolower( trim( $svc_name ) );
                        if ( $svc_key !== '' ) {
                            if ( isset( $cat_seen[ $svc_key ] ) ) continue;
                            $cat_seen[ $svc_key ] = true;
                        }
                        $cat_rows[] = [ 'item' => $entry_item, 'doc_nev' => $entry_doc['nev'] ];
                    }
                }

                usort( $cat_rows, function( $a, $b ) {
                    $na = isset( $a['item']['szolgaltatas'] ) ? (string) $a['item']['szolgaltatas'] : '';
                    $nb = isset( $b['item']['szolgaltatas'] ) ? (string) $b['item']['szolgaltatas'] : '';
                    return bmm_hu_compare( $na, $nb );
                } );

                foreach ( $cat_rows as $cat_row ) {
                    echo bmdoc_render_row( $cat_row['item'], $cat_row['doc_nev'], $book_label, $show_price );
                }
                ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <div id="no-results-msg"><?php echo esc_html($no_res); ?></div>

    </div><!-- /.price-list-container -->

    <script>
    jQuery(document).ready(function($){
        // Keresés
        $('#bmdoc-search-input').on('keyup', function(){
            var v = $(this).val().toLowerCase();
            var c = 0;

            <?php if ($layout === 'grid') : ?>
            $('.price-card').each(function(){
                var m = $(this).text().toLowerCase().indexOf(v) > -1;
                $(this).toggle(m);
                if(m) c++;
            });
            <?php else : ?>
            // Lista nézet: minden szakterület egy harmonika, body-jában a vizsgálat-sorok
            $('.accordion-item').each(function(){
                var $item     = $(this);
                var headText  = $item.find('.accordion-header h3').text().toLowerCase();
                var headMatch = headText.indexOf(v) > -1;

                var rowMatchCount = 0;
                $item.find('.price-item-row').each(function(){
                    var $row = $(this);
                    var rowText = $row.text().toLowerCase();
                    var match = (v === '') || headMatch || rowText.indexOf(v) > -1;
                    $row.toggle(match);
                    if (match && v !== '') rowMatchCount++;
                });

                var visible = (v === '') || headMatch || rowMatchCount > 0;
                $item.toggle(visible);
                if (visible) {
                    c++;
                    if (v !== '') {
                        $item.find('.accordion-body').show();
                        $item.find('.accordion-header').addClass('active');
                    }
                }
            });
            <?php endif; ?>

            $('#no-results-msg').toggle(c === 0 && v !== '');
        });

        // Kattintás tracking
        $(document).on('click', '.book-btn', function(){
            $.post('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                action:   'bmdoc_track_click',
                service:  $(this).data('name'),
                category: $(this).data('cat')
            });
        });
    });
    </script>
    <?php
    return ob_get_clean();
}

/* =========================================================================
 * 21. ROW RENDERELÉS
 * ====================================================================== */

function bmdoc_render_row( $item, $doc_name, $book_label, $show_price = true ) {
    // Több lehetséges mezőnév próbálása (különböző API verziókhoz)
    $service = bmdoc_pick_field( $item, [ 'szolgaltatas', 'service_name', 'service' ] );

    // Ha nincs szolgáltatás név, üres sort nem renderelünk (Puzder Bernadett-féle eset)
    if ( $service === '' ) return '';

    $info   = bmdoc_pick_field( $item, [ 'info_text', 'leiras', 'description' ] );
    $link   = bmdoc_pick_field( $item, [ 'link', 'naptar_link', 'booking_url' ], '#' );
    $min_ar = $item['min_ar'] ?? ( $item['ar'] ?? ( $item['price'] ?? null ) );
    $max_ar = $item['max_ar'] ?? null;

    $info_full = trim( (string) $info );
    $has_price = bmdoc_parse_price( $min_ar ) !== null || ( is_string( $min_ar ) && trim( $min_ar ) !== '' );
    $price     = $has_price ? bmdoc_format_price( $min_ar, $max_ar ) : '';

    // A vizsgálat alatti kis leírás (info szöveg) csak akkor jelenik meg, ha van –
    // üres leírásnál nem kerül ki üres blokk.
    $reception_only = bmdoc_is_reception_only( $item );

    $out  = '<div class="price-item-row' . ( $reception_only ? ' bm-reception-only' : '' ) . '">';
    $out .= '<div class="service-info-box">';
    $out .= '<div class="service-name">' . esc_html( $service ) . ( $reception_only ? ' ' . bmm_render_phone_notice_icon() : '' ) . '</div>';
    if ( $info_full !== '' ) $out .= '<div class="service-description">' . esc_html( $info_full ) . '</div>';
    $out .= '</div>';
    $out .= '<div class="price-booking-box">';
    if ( $show_price && $price !== '' ) $out .= '<div class="price-pill">' . $price . '</div>';
    if ( $reception_only ) {
        $out .= bmm_render_phone_booking();
    } else {
        $out .= '<a href="' . esc_url($link) . '" class="book-btn" target="_blank" rel="noopener" data-name="' . esc_attr($service) . '" data-cat="' . esc_attr($doc_name) . '">' . esc_html($book_label) . '</a>';
    }
    $out .= '</div>';
    $out .= '</div>';

    return $out;
}
