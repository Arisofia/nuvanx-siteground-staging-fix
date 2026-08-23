<?php
require_once __DIR__ . '/../../lib/nvx-content-hygiene-rules.php';

// ── Bootstrap ─────────────────────────────────────────────────────────────────

/** @global wpdb $wpdb */
global $wpdb;

$start    = microtime( true );
$site_url = (string) get_option( 'siteurl', '(unknown)' );

printf( "=== NVX Content Audit: Divergence Report ===\n" );
printf( "Site        : %s\n", $site_url );
printf( "Generated   : %s\n", current_time( 'Y-m-d H:i:s' ) );
printf( "WP prefix   : %s\n\n", $wpdb->prefix );

// ── Helpers ───────────────────────────────────────────────────────────────────

/** @var list<array{post_id:int,slug:string,field:string,type:string,pattern:string}> */
$pending = [];
$errors  = [];

// ── Fetch posts (one query, kept in memory) ───────────────────────────────────

$fields     = nvx_hygiene_fields();
$fields_sql = implode( ', ', array_map( fn( $f ) => "`$f`", $fields ) );

$posts = $wpdb->get_results(
    "SELECT ID, post_name, post_status, post_type, {$fields_sql}
       FROM {$wpdb->posts}
      WHERE post_status IN ('publish','draft','private','pending')
        AND post_type   NOT IN ('revision','nav_menu_item','attachment')
      ORDER BY ID ASC",
    ARRAY_A
);

if ( null === $posts ) {
    fwrite( STDERR, "[FATAL] wpdb returned null. Last error: " . $wpdb->last_error . "\n" );
    exit( 1 );
}

printf( "Posts scanned : %d\n\n", count( $posts ) );

// ── Block 1: String replacement audit ─────────────────────────────────────────

echo "--- Block 1: String Replacement Audit ---\n";

foreach ( nvx_hygiene_str_reps() as $rule ) {
    $found_any = false;

    foreach ( $posts as $post ) {
        foreach ( $fields as $field ) {
            $value = $post[ $field ] ?? '';
            if ( '' === $value ) {
                continue;
            }
            if ( false === mb_strpos( $value, $rule['from'] ) ) {
                continue;
            }

            $found_any = true;
            $pending[] = [
                'post_id' => (int) $post['ID'],
                'slug'    => $post['post_name'],
                'field'   => $field,
                'type'    => 'string',
                'pattern' => $rule['from'],
            ];

            printf(
                "[PENDING] ID %-5d | %-44s | %-16s | \"%s\"\n",
                $post['ID'],
                '/' . $post['post_name'] . '/',
                $field,
                $rule['from']
            );
        }
    }

    if ( ! $found_any ) {
        printf( "[CLEAN  ]                                                             | \"%s\"\n", $rule['from'] );
    }
}

echo "\n";

// ── Block 2: Regex replacement audit ──────────────────────────────────────────

echo "--- Block 2: Regex Replacement Audit ---\n";

foreach ( nvx_hygiene_regex_reps() as $rule ) {
    $pcre      = '/' . $rule['pattern'] . '/' . $rule['flags'];
    $found_any = false;

    foreach ( $posts as $post ) {
        foreach ( $fields as $field ) {
            $value = $post[ $field ] ?? '';
            if ( '' === $value ) {
                continue;
            }

            $match = preg_match( $pcre, $value, $m );
            if ( false === $match ) {
                printf(
                    "[ERROR  ] ID %-5d | %-44s | %-16s | regex match failed\n",
                    $post['ID'],
                    '/' . $post['post_name'] . '/',
                    $field
                );
                $errors[] = sprintf(
                    'Regex audit failed: ID %d field %s pattern /%s/',
                    $post['ID'],
                    $field,
                    $rule['pattern']
                );
                continue;
            }
            if ( 0 === $match ) {
                continue;
            }

            $found_any = true;
            $pending[] = [
                'post_id' => (int) $post['ID'],
                'slug'    => $post['post_name'],
                'field'   => $field,
                'type'    => 'regex',
                'pattern' => $rule['pattern'],
            ];

            printf(
                "[PENDING] ID %-5d | %-44s | %-16s | /%s/\n",
                $post['ID'],
                '/' . $post['post_name'] . '/',
                $field,
                $rule['pattern']
            );
        }
    }

    if ( ! $found_any ) {
        printf( "[CLEAN  ] /%s/\n", $rule['pattern'] );
    }
}

echo "\n";

// ── Block 3: Retired strategy page audit ──────────────────────────────────────

echo "--- Block 3: Retired Strategy Page Audit ---\n";

$retired_pending = 0;

foreach ( nvx_hygiene_retired_strategy_pages() as $slug => $contract ) {
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT ID, post_status, post_type
               FROM {$wpdb->posts}
              WHERE post_name = %s
                AND post_type NOT IN ('revision','nav_menu_item','attachment')
              ORDER BY ID ASC",
            $slug
        ),
        ARRAY_A
    );

    if ( null === $rows ) {
        printf( "[ERROR  ] /%s/ — database lookup failed\n", $slug );
        $errors[] = "Retired page query failed: /{$slug}/";
        continue;
    }

    if ( empty( $rows ) ) {
        printf( "[OK     ] /%s/ — no content record; redirect target %s\n", $slug, $contract['target'] );
        continue;
    }

    foreach ( $rows as $row ) {
        if ( 'trash' === $row['post_status'] ) {
            printf( "[OK     ] /%s/ — ID %d status=trash; redirect target %s\n", $slug, $row['ID'], $contract['target'] );
            continue;
        }

        $retired_pending++;
        $pending[] = [
            'post_id' => (int) $row['ID'],
            'slug'    => $slug,
            'field'   => 'post_status',
            'type'    => 'retired_page',
            'pattern' => 'expected=trash;target=' . $contract['target'],
        ];
        printf(
            "[PENDING] ID %-5d | /%-42s | post_status=%s must become trash; redirect=%s\n",
            $row['ID'],
            $slug . '/',
            $row['post_status'],
            $contract['target']
        );
    }
}

echo "\n";

// ── Block 4: Legal page H1 audit ──────────────────────────────────────────────

echo "--- Block 4: Legal Page H1 Audit ---\n";

$h1_issues = 0;

foreach ( nvx_hygiene_legal_pages() as $slug => $expected ) {
    $page = get_page_by_path( $slug, OBJECT, 'page' );

    if ( ! $page ) {
        printf( "[MISSING] /%s/ — page not found in database\n", $slug );
        $errors[]  = "Legal page not found: /{$slug}/";
        $h1_issues++;
        continue;
    }

    $count = preg_match_all( '/<h1[^>]*>(.*?)<\/h1>/is', $page->post_content, $m );

    if ( 0 === $count ) {
        printf( "[NO H1  ] /%s/ — no <h1> tag found (ID %d)\n", $slug, $page->ID );
        $h1_issues++;
    } elseif ( $count > 1 ) {
        printf( "[MULTI  ] /%s/ — %d <h1> tags (ID %d)\n", $slug, $count, $page->ID );
        $h1_issues++;
    } else {
        $found = trim( wp_strip_all_tags( $m[1][0] ) );
        if ( $found === $expected ) {
            printf( "[OK     ] /%s/ — H1: \"%s\" (ID %d)\n", $slug, $expected, $page->ID );
        } else {
            printf(
                "[MISMATCH] /%s/ — expected \"%s\", found \"%s\" (ID %d)\n",
                $slug,
                $expected,
                $found,
                $page->ID
            );
            $h1_issues++;
        }
    }
}

echo "\n";

// ── Summary ───────────────────────────────────────────────────────────────────

$elapsed       = round( microtime( true ) - $start, 2 );
$total         = count( $pending );
$unique_posts  = count( array_unique( array_column( $pending, 'post_id' ) ) );
$str_count     = count( array_filter( $pending, fn( $r ) => 'string' === $r['type'] ) );
$rx_count      = count( array_filter( $pending, fn( $r ) => 'regex' === $r['type'] ) );
$retired_count = count( array_filter( $pending, fn( $r ) => 'retired_page' === $r['type'] ) );

echo "=== SUMMARY ===\n";
printf( "Posts scanned                : %d\n", count( $posts ) );
printf( "Pending (string)             : %d\n", $str_count );
printf( "Pending (regex)              : %d\n", $rx_count );
printf( "Pending (retired pages)      : %d\n", $retired_count );
printf( "Legal page H1 issues         : %d\n", $h1_issues );
printf( "Total pending records        : %d across %d unique posts\n", $total, $unique_posts );
printf( "Elapsed                      : %ss\n\n", $elapsed );

// Machine-readable JSON for CI / log parsing
echo "--- JSON Summary ---\n";
echo json_encode( [
    'audit_timestamp' => current_time( 'c' ),
    'site_url'        => $site_url,
    'posts_scanned'   => count( $posts ),
    'string_pending'  => $str_count,
    'regex_pending'   => $rx_count,
    'retired_pending' => $retired_count,
    'h1_issues'       => $h1_issues,
    'total_pending'   => $total,
    'unique_posts'    => $unique_posts,
    'pending_records' => array_map(
        fn( $r ) => [
            'post_id' => $r['post_id'],
            'slug'    => $r['slug'],
            'field'   => $r['field'],
            'type'    => $r['type'],
            'pattern' => $r['pattern'],
        ],
        $pending
    ),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n\n";

// ── Exit ──────────────────────────────────────────────────────────────────────

if ( 0 === $total && 0 === $h1_issues && empty( $errors ) ) {
    echo "Status: AUDIT_CLEAN\n";
    exit( 0 );
}

// H1 issues or database/query errors are non-migratable and require review.
if ( $h1_issues > 0 || ! empty( $errors ) ) {
    printf( "Status: AUDIT_FAIL h1_issues=%d errors=%d\n", $h1_issues, count( $errors ) );
    exit( 1 );
}

// String/regex hygiene and retired-page status are intentionally migratable.
printf( "Status: AUDIT_PENDING_MIGRABLE pending=%d retired=%d\n", $total, $retired_pending );
exit( 0 );
