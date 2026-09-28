<?php
/**
 * all_search_replace
 *
 * Search and replace a term across an allowlisted set of Textpattern
 * tables/columns, from a single admin tab, with a preview step before
 * anything is written.
 *
 * Flow: search (read-only) -> tick rows + enter a replacement -> preview
 * (read-only, re-fetches current values) -> confirm -> replace (writes,
 * requires the TXP CSRF token).
 *
 * Restricted to Publisher (privs level 1) by default
 */

if (@txpinterface == 'admin') {
    add_privs('all_search_replace', '1');
    register_callback('all_search_replace', 'all_search_replace');
    register_tab('extensions', 'all_search_replace', 'Search &amp; Replace');
    register_callback('all_search_replace_head', 'admin_side', 'head_end');
}

// -----------------------------------------------------------------------
// Styles -- injected into <head> via the head_end callback registered
// above, gated on the event param to avoid leaking this CSS elsewhere.
// -----------------------------------------------------------------------

function all_search_replace_head()
{
    if (gps('event') !== 'all_search_replace') {
        return;
    }

    echo <<<EOCSS
    <style>
      #all_search_replace_container {
        display: grid;
        grid-template-columns: auto auto 1fr;
        gap: 1em;
        min-height: 100%;
      }

      .all_search_replace_criteria,
      .all_search_replace_results_header {
        position: sticky;
        top: 2.75em;
        z-index: 1;
        background: var(--clr-bkgd-box);
        padding-inline: 1em;
        padding-block: .25em;
        align-self: start;
      }

      .all_search_replace_results_header {
        display: flex;
        gap: 1em;
      }

      .all_search_replace_results {
        thead {
          top: 6.5em;
          position: sticky;
          z-index: 1;
        }

        > table thead {
          top: 2.75em;
        }
      }
    </style>
    EOCSS;
}

// -----------------------------------------------------------------------
// Configuration: the allowlist.
//
// Deliberately an allowlist, not an information_schema scan of every text
// column in the database. Widen this list only for tables you've
// checked hold plain free-text content.
//
// Each table entry needs its own 'key' column, because Textpattern is not
// consistent about this: the articles table's primary key is `ID`
// (uppercase), txp_category/txp_image/txp_link use lowercase `id`, and
// txp_section has no id column at all -- it's keyed by `name`.
//
// Note on textpattern.Body / Body_html (and Excerpt / Excerpt_html): these
// are the raw Textile source and its rendered cache respectively. A write
// to Body alone would leave Body_html stale until the article is next
// saved through the normal edit form -- the front end would keep showing
// the old text. all_search_replace_html_mirrors below tells the replace
// step which columns need to be kept in sync together.
// -----------------------------------------------------------------------

$GLOBALS['all_search_replace_tables'] = array(
    'textpattern' => array(
        'key' => 'ID',
        'columns' => array('Title', 'Body', 'Body_html', 'Excerpt', 'Excerpt_html'),
    ),
    'txp_category' => array(
        'key' => 'id',
        'columns' => array('title'),
    ),
    'txp_image' => array(
        'key' => 'id',
        'columns' => array('caption', 'alt'),
    ),
    'txp_section' => array(
        'key' => 'name',
        'columns' => array('title'),
    ),
    'txp_link' => array(
        'key' => 'id',
        'columns' => array('url', 'linkname', 'description'),
    ),
);

// When a replace targets the column on the left, also apply the same
// find/replace to the column on the right in the same update -- keeps the
// Textile source and its rendered cache from drifting apart. Safe even if
// the term isn't present in the mirror column (a no-op str_replace).
$GLOBALS['all_search_replace_html_mirrors'] = array(
    'textpattern' => array(
        'Body' => 'Body_html',
        'Excerpt' => 'Excerpt_html',
    ),
);

// Per-table edit-link builder, so a result row can jump straight into the
// normal TXP editor for that content instead of just naming it. Only
// tables with a numeric id are listed -- txp_category and txp_section are
// keyed by name/parent in the admin UI, not a simple id, so no link is
// built for them rather than building a wrong one.
$GLOBALS['all_search_replace_edit_links'] = array(
    'textpattern' => 'index.php?event=article&step=edit&ID=',
    'txp_image' => 'index.php?event=image&step=image_edit&id=',
    'txp_link' => 'index.php?event=link&step=link_edit&id=',
);

// -----------------------------------------------------------------------
// Custom fields (glz_custom_fields)
//
// These are discovered from the live schema (SHOW COLUMNS ... LIKE
// 'custom\_%')
// -----------------------------------------------------------------------

$GLOBALS['all_search_replace_custom_labels'] = array(
    // 'custom_11' => 'Collection', // override if required
);

function all_search_replace_custom_columns()
{
    static $cols = null;

    if ($cols !== null) {
        return $cols;
    }

    $cols = array();

    try {
        // getCustomFields() is TXP core's own way of listing which
        // custom_N columns are actually in use (standard 1-10 plus any
        // glz-added ones)
        foreach (array_keys(getCustomFields()) as $index) {
            $cols[] = 'custom_'.$index;
        }

        natsort($cols);
        $cols = array_values($cols);
    } catch (Throwable $e) {
        // Discovery failing just means custom fields aren't searched --
        // not fatal to the rest of the plugin.
    }

    return $cols;
}

function all_search_replace_custom_label($column)
{
    if (isset($GLOBALS['all_search_replace_custom_labels'][$column])) {
        return $GLOBALS['all_search_replace_custom_labels'][$column];
    }

    global $prefs;

    // $prefs['custom_N_set'] is populated by TXP core at bootstrap. It's still a
    // snake_case machine name rather than a display label, so it's
    // prettified (underscores to spaces, leading capital) rather than
    // shown raw.
    if (preg_match('/^custom_(\d+)$/i', $column, $m)) {
        $pref_key = 'custom_'.$m[1].'_set';

        if (!empty($prefs[$pref_key])) {
            return ucfirst(str_replace('_', ' ', $prefs[$pref_key]));
        }
    }

    return $column;
}

function all_search_replace_display_column($table, $column)
{
    if ($table === 'textpattern' && preg_match('/^custom_\d+$/i', $column)) {
        return all_search_replace_custom_label($column);
    }

    return $column;
}

// -----------------------------------------------------------------------
// Dispatcher
// -----------------------------------------------------------------------

function all_search_replace($event, $step)
{
    pagetop('Search &amp; Replace');

    $available_steps = array(
        'all_search_replace_search' => false,     // read-only, no token required
        'all_search_replace_preview' => false,    // read-only, re-fetches current values
        'all_search_replace_do_replace' => true,  // writes -- requires _txp_token
        'all_search_replace_render_log' => false, // read-only, lists the audit log
        'all_search_replace_bulk_list' => false,     // read-only -- filter-only article listing, no term
        'all_search_replace_bulk_preview' => false,  // read-only, re-fetches current values
        'all_search_replace_bulk_assign' => true,    // writes -- requires _txp_token
    );

    if ($step && bouncer($step, $available_steps)) {
        $step();
    } else {
        all_search_replace_form();
    }
}

// -----------------------------------------------------------------------
// Page chrome
//
// Every step wraps its output in the same #all_search_replace_container
// grid, with a sticky .all_search_replace_criteria column and a
// .all_search_replace_results column (see the head_end callback above for
// the actual CSS). Factored out here so each step only has to supply its
// own content
// -----------------------------------------------------------------------

function all_search_replace_page_open()
{
    echo n.'<h1 class="txp-heading">Search and Replace</h1>';
    echo n.'<div id="all_search_replace_container">';
}

function all_search_replace_page_close()
{
    echo n.'</div>'; // #all_search_replace_container
}

function all_search_replace_back_link()
{
    echo n.'<p><a href="index.php?event=all_search_replace">&#8592; Back to search</a></p>';
}

function all_search_replace_criteria_open()
{
    echo n.'<div class="all_search_replace_criteria">';
}

function all_search_replace_criteria_close()
{
    echo n.'</div>'; // .all_search_replace_criteria
}

function all_search_replace_results_open()
{
    echo n.'<div class="all_search_replace_results">';
}

function all_search_replace_results_close()
{
    echo n.'</div>'; // .all_search_replace_results
}

// -----------------------------------------------------------------------
// Step: search form
// -----------------------------------------------------------------------

function all_search_replace_form($term = '', $case_sensitive = false, $results = null, $skipped = array(), $filter_values = null, $filtered_articles = null)
{
    if ($filter_values === null) {
        $filter_values = array('filter_section' => '', 'filter_category' => '', 'filter_custom_field' => '', 'filter_custom_value' => '');
    }

    all_search_replace_page_open();

    echo n.'<form method="get" action="index.php" class="all_search_replace_criteria">'
        .eInput('all_search_replace')
        .sInput('all_search_replace_search')
        .n.'<p>'
        .n.'<label for="asr_term">Search term</label> '
        .n.'<input type="text" id="asr_term" name="term" value="'.txpspecialchars($term).'" size="40" />'
        .n.'</p>'
        .n.'<p><label>'
        .n.'<input type="checkbox" name="case_sensitive" value="1"'.($case_sensitive ? ' checked="checked"' : '').' /> Case sensitive'
        .n.'</label></p>';

    all_search_replace_render_filter_fields($filter_values);

    echo n.'<p><input type="submit" class="publish" value="Search" /></p>'
        .n.'<p><a href="index.php?event=all_search_replace&amp;step=all_search_replace_render_log">View change log &#8594;</a></p>'
        .n.'<p><a href="index.php?event=all_search_replace&amp;step=all_search_replace_bulk_list">Bulk field assignment &#8594;</a></p>'
        .n.'</form>';

    if ($skipped) {
        echo n.'<p><em>Skipped (column not found -- fix the allowlist config): '
            .txpspecialchars(implode(', ', $skipped)).'</em></p>';
    }

    if ($results !== null) {
        all_search_replace_render_results($term, $case_sensitive, $results);
    } elseif ($filtered_articles !== null) {
        // No search term -- there's nothing to search FOR, but a filter
        // is set, so rather than doing nothing, show the same filtered
        // article listing the bulk-assignment step uses. It's already a
        // complete, actionable view (edit links, select-all, and its own
        // route into bulk assignment), so this just reuses it in place
        // rather than redirecting.
        echo n.'<p><em>No search term entered -- listing articles matching the filter instead.</em></p>';
        all_search_replace_render_bulk_list($filtered_articles);
    }

    all_search_replace_page_close();
}

// -----------------------------------------------------------------------
// Step: run search (read-only)
// -----------------------------------------------------------------------

function all_search_replace_search()
{
    $term = gps('term');
    $case_sensitive = (bool) gps('case_sensitive');
    list($filter_where, $filter_values) = all_search_replace_read_filters();

    if ($term === '' || $term === false) {
        $filtered_articles = all_search_replace_filters_active($filter_values)
            ? all_search_replace_bulk_query($filter_where)
            : null;

        all_search_replace_form('', false, null, array(), $filter_values, $filtered_articles);
        return;
    }

    list($results, $skipped) = all_search_replace_run_search($term, $case_sensitive, $filter_where);
    all_search_replace_form($term, $case_sensitive, $results, $skipped, $filter_values);
}

function all_search_replace_run_search($term, $case_sensitive = false, $filter_where = array())
{
    $results = array();
    $skipped = array();
    $term_esc = doSlash($term);
    $like_clause = $case_sensitive
        ? "LIKE BINARY '%".$term_esc."%'"
        : "LIKE '%".$term_esc."%'";

    foreach ($GLOBALS['all_search_replace_tables'] as $table => $conf) {
        $key = $conf['key'];
        $columns = $conf['columns'];

        if ($table === 'textpattern') {
            $columns = array_unique(array_merge($columns, all_search_replace_custom_columns()));
        } elseif ($filter_where) {
            // Filters (Section/Category/custom-field) are textpattern-only
            // concepts. Rather than search other tables unfiltered and
            // imply the scope applied there too, skip them entirely once
            // a filter is active.
            continue;
        }

        foreach ($columns as $column) {
            $where = "`".doSlash($column)."` ".$like_clause;

            if ($table === 'textpattern' && $filter_where) {
                $where .= ' AND '.implode(' AND ', $filter_where);
            }

            // Defensive: a bad table/column name in the allowlist above
            // (wrong case, renamed column, table from a plugin you don't
            // have installed) should skip that one entry, not fatal the
            // whole page the way an uncaught mysqli_sql_exception does.
            try {
                $rows = safe_rows(
                    "`".doSlash($key)."` as row_key, `".doSlash($column)."` as val",
                    doSlash($table),
                    $where
                );
            } catch (Throwable $e) {
                $skipped[] = $table.'.'.$column.' ('.$e->getMessage().')';
                continue;
            }

            if (!$rows) {
                continue;
            }

            foreach ($rows as $row) {
                // The LIKE above is a broad net; for a pipe-delimited list
                // column it also catches the term sitting inside an
                // unrelated token (e.g. 'Molitor' inside 'MolitorGrande').
                // Re-check for a whole-token match before it's shown as a
                // hit -- plain free-text columns already satisfy this by
                // construction, so the check is a no-op for them.
                if (all_search_replace_is_list_column($table, $column)
                    && !all_search_replace_contains($table, $column, $row['val'], $term, $case_sensitive)) {
                    continue;
                }

                $results[] = array(
                    'table' => $table,
                    'column' => $column,
                    'id' => $row['row_key'],
                    'context' => all_search_replace_context($row['val'], $term, $case_sensitive),
                );
            }
        }
    }

    return array($results, $skipped);
}

// Builds a short escaped snippet around the first match, with the match
// itself wrapped in <mark>. Escaping happens on each piece before
// concatenation so the highlight markup itself never gets escaped away.
function all_search_replace_context($value, $term, $case_sensitive, $pad = 40)
{
    $value = (string) $value;
    $pos = $case_sensitive ? mb_strpos($value, $term) : mb_stripos($value, $term);

    if ($pos === false) {
        // Shouldn't normally happen (LIKE already matched), but fall back
        // to a plain truncated snippet rather than showing nothing.
        return txpspecialchars(mb_substr($value, 0, $pad * 2)).'&#8230;';
    }

    $term_len = mb_strlen($term);
    $start = max(0, $pos - $pad);
    $before = mb_substr($value, $start, $pos - $start);
    $match = mb_substr($value, $pos, $term_len);
    $after = mb_substr($value, $pos + $term_len, $pad);

    return ($start > 0 ? '&#8230;' : '')
        .txpspecialchars($before)
        .'<mark>'.txpspecialchars($match).'</mark>'
        .txpspecialchars($after)
        .'&#8230;';
}

// Truncated before/after snippet centred on the first character where two
// strings diverge -- used by the preview step. Works regardless of how the
// replacement changes the string's length, unlike reusing the search-term
// position from all_search_replace_context().
function all_search_replace_diff_context($old, $new, $pad = 40)
{
    $old = (string) $old;
    $new = (string) $new;
    $len = min(mb_strlen($old), mb_strlen($new));
    $pos = 0;

    while ($pos < $len && mb_substr($old, $pos, 1) === mb_substr($new, $pos, 1)) {
        $pos++;
    }

    $start = max(0, $pos - $pad);
    $prefix = $start > 0 ? '&#8230;' : '';

    return array(
        $prefix.txpspecialchars(mb_substr($old, $start, $pad * 2)).'&#8230;',
        $prefix.txpspecialchars(mb_substr($new, $start, $pad * 2)).'&#8230;',
    );
}

function all_search_replace_edit_link($table, $id)
{
    $map = $GLOBALS['all_search_replace_edit_links'];

    return isset($map[$table]) ? $map[$table].intval($id) : false;
}

// Own toggle-all wiring rather than relying on TXP core's internal
// multi-edit jQuery plugin -- that plugin binds to a specific
// selector/container shape (a Multi-Edit dropdown, particular row
// markup) neither results table here has, so hooking into it directly
// would be fragile across TXP versions/themes. Shared between the
// search-results and bulk-assignment listings, which each check their
// own set of row checkboxes by name.
function all_search_replace_select_all_script($checkbox_name)
{
    echo script_js(<<<END_SCRIPT
        \$(function () {
            var \$selectAll = \$('#select_all');
            var \$boxes = \$('input[name="{$checkbox_name}"]');

            \$selectAll.on('change', function () {
                \$boxes.prop('checked', this.checked);
            });

            \$boxes.on('change', function () {
                \$selectAll.prop('checked', \$boxes.length > 0 && \$boxes.filter(':checked').length === \$boxes.length);
            });
        });
        END_SCRIPT
    );
}

// -----------------------------------------------------------------------
// Article context -- Status/Section/Category alongside a
// textpattern result row, so a hit can be judged without opening the
// article editor. One extra safe_row() per distinct article ID (cached
// here so an article matching on more than one column, e.g. Title and
// Body both containing the term, only costs one query), which is fine
// for an admin tool but would want joining into the search query itself
// if result sets ever grow large.
// -----------------------------------------------------------------------

function all_search_replace_article_context($id)
{
    static $cache = array();

    if (isset($cache[$id])) {
        return $cache[$id];
    }

    $row = false;

    try {
        $row = safe_row('Status, Section, Category1, Category2', 'textpattern', "ID = '".doSlash($id)."'");
    } catch (Throwable $e) {
        // Leave $row false -- context cells just render empty.
    }

    return $cache[$id] = $row ?: array('Status' => '', 'Section' => '', 'Category1' => '', 'Category2' => '');
}

// STATUS_DRAFT etc. are TXP core constants (1-5); mapped here to short
// labels rather than via a core lookup function, since the constants
// themselves are the stable part of the API.
function all_search_replace_status_label($status)
{
    $labels = array(
        STATUS_DRAFT => 'Draft',
        STATUS_HIDDEN => 'Hidden',
        STATUS_PENDING => 'Pending',
        STATUS_LIVE => 'Live',
        STATUS_STICKY => 'Sticky',
    );

    return isset($labels[$status]) ? $labels[$status] : (string) $status;
}

// Renders the three context <td>s for one result/preview row -- empty
// cells for non-textpattern tables, where Status/Section/Category don't
// apply.
function all_search_replace_context_cells($table, $id)
{
    if ($table !== 'textpattern') {
        return n.'<td></td><td></td><td></td>';
    }

    $ctx = all_search_replace_article_context($id);
    $category = implode(', ', array_filter(array($ctx['Category1'], $ctx['Category2'])));

    return n.'<td>'.txpspecialchars(all_search_replace_status_label($ctx['Status'])).'</td>'
        .n.'<td>'.txpspecialchars($ctx['Section']).'</td>'
        .n.'<td>'.txpspecialchars($category).'</td>';
}

// Packs table/column/row-key into one opaque string for a checkbox value
// (base64+json rather than a delimiter, since a row key -- e.g. a section
// name -- could in principle contain any character).
function all_search_replace_encode_target($table, $column, $id)
{
    return base64_encode(json_encode(array('t' => $table, 'c' => $column, 'k' => $id)));
}

function all_search_replace_decode_target($encoded)
{
    $data = json_decode(base64_decode((string) $encoded, true), true);

    if (!is_array($data) || !isset($data['t'], $data['c'], $data['k'])) {
        return false;
    }

    return array('table' => $data['t'], 'column' => $data['c'], 'id' => $data['k']);
}

// Fetches a column's CURRENT value fresh from the DB -- never trust the
// value a search or preview step captured earlier; someone may have edited
// it in between. Returns false if the row/column no longer exists.
function all_search_replace_fetch_value($table, $key_col, $id, $column)
{
    if (!isset($GLOBALS['all_search_replace_tables'][$table])) {
        return false;
    }

    try {
        return safe_field(
            "`".doSlash($column)."`",
            doSlash($table),
            "`".doSlash($key_col)."` = '".doSlash($id)."'"
        );
    } catch (Throwable $e) {
        return false;
    }
}

// -----------------------------------------------------------------------
// glz_custom_fields list handling
//
// glz_custom_fields pipe-delimits every field type by default (a
// multi-select joins its choices with '|', and a single-select stores one
// token the same way) -- textarea is the only field type that's free text
// instead. date/time/custom-script aren't considered.
// custom_fields (id, name, value) is glz's own storage for a field's
// defined choices -- rows like name='custom_11_set' exist only for
// select/checkbox/radio fields, so any custom_N with rows there is
// confirmed pipe-delimited, and anything absent from it is presumed
// textarea. Discovered live rather than hardcoded, same reasoning as
// all_search_replace_custom_columns() above: it should track this
// install's actual field types without needing this file edited every
// time a field is added or its type changes.
//
// Substring search/replace on a list column is wrong in two directions at
// once: 'Molitor' also matches inside an unrelated token like
// 'MolitorGrande' (false positive), and a plain str_replace on
// 'Bianco|Molitor' leaves a stray '|' behind once 'Molitor' is replaced
// with '' (malformed output). Token-exact matching below fixes both, and
// collapses what would otherwise be three separate searches (leading,
// trailing, whole-value) into one.
// -----------------------------------------------------------------------

$GLOBALS['all_search_replace_list_delimiter'] = '|';

function all_search_replace_list_custom_columns()
{
    static $cols = null;

    if ($cols !== null) {
        return $cols;
    }

    $cols = array();

    try {
        $names = safe_column('DISTINCT name', 'custom_fields', "name LIKE 'custom\\_%\\_set'");

        if ($names) {
            foreach ($names as $name) {
                if (preg_match('/^custom_(\d+)_set$/i', $name, $m)) {
                    $cols['custom_'.$m[1]] = true;
                }
            }
        }
    } catch (Throwable $e) {
        // Discovery failing just means every custom_* column falls back
        // to plain substring search/replace -- not fatal.
    }

    return $cols;
}

function all_search_replace_is_list_column($table, $column)
{
    if ($table !== 'textpattern' || !preg_match('/^custom_\d+$/i', $column)) {
        return false; // native columns (Title, Body, etc.) are always free text
    }

    $known_lists = all_search_replace_list_custom_columns();

    return isset($known_lists[$column]);
}

// Exact-token membership test for a pipe-delimited list column; plain
// substring test for everything else. Used wherever the plugin needs to
// decide whether a value still "contains" the term -- search-result
// filtering, and the re-check done at preview and at do_replace time.
function all_search_replace_contains($table, $column, $value, $term, $case_sensitive)
{
    if (all_search_replace_is_list_column($table, $column)) {
        $delimiter = $GLOBALS['all_search_replace_list_delimiter'];
        $tokens = explode($delimiter, (string) $value);

        foreach ($tokens as $token) {
            $match = $case_sensitive ? ($token === $term) : (mb_strtolower($token) === mb_strtolower($term));

            if ($match) {
                return true;
            }
        }

        return false;
    }

    return $case_sensitive
        ? (mb_strpos((string) $value, $term) !== false)
        : (mb_stripos((string) $value, $term) !== false);
}

function all_search_replace_apply($table, $column, $value, $term, $replace_with, $case_sensitive)
{
    if (all_search_replace_is_list_column($table, $column)) {
        $delimiter = $GLOBALS['all_search_replace_list_delimiter'];
        $tokens = explode($delimiter, (string) $value);

        foreach ($tokens as $i => $token) {
            $match = $case_sensitive ? ($token === $term) : (mb_strtolower($token) === mb_strtolower($term));

            if ($match) {
                $tokens[$i] = $replace_with;
            }
        }

        // Drop empty tokens (term removed with an empty replacement) so
        // we don't leave stray delimiters behind, e.g. "Bianco||Beige" or
        // a trailing/leading '|'.
        $tokens = array_filter($tokens, function ($t) { return $t !== ''; });

        return implode($delimiter, $tokens);
    }

    return $case_sensitive
        ? str_replace($term, $replace_with, $value)
        : str_ireplace($term, $replace_with, $value);
}

// -----------------------------------------------------------------------
// Filters (roadmap #1) -- Section / Category / custom-field-value scope.
// These are textpattern-table concepts only, so they never apply to the
// other allowlisted tables. Shared between the text-search step above
// and the bulk-assignment steps below, since both start from "which
// articles are in scope" -- text search narrows a LIKE scan to that
// scope, bulk assignment lists that scope directly with no term at all.
// -----------------------------------------------------------------------

function all_search_replace_filter_sections()
{
    try {
        return safe_rows('name, title', 'txp_section', "name != 'default' ORDER BY title");
    } catch (Throwable $e) {
        return array();
    }
}

function all_search_replace_filter_categories()
{
    try {
        return safe_rows('name, title', 'txp_category', "type = 'article' ORDER BY title");
    } catch (Throwable $e) {
        return array();
    }
}

// Reads filter_* fields from the current request (GET or POST -- both
// the search form and the bulk-list form use them) and returns
// array($where_fragments, $values): the WHERE fragments to AND onto a
// textpattern query, and the raw values so a form can echo the current
// scope back into its selects/input on redisplay.
function all_search_replace_read_filters()
{
    $values = array(
        'filter_section' => gps('filter_section'),
        'filter_category' => gps('filter_category'),
        'filter_custom_field' => gps('filter_custom_field'),
        'filter_custom_value' => trim((string) gps('filter_custom_value')),
    );

    $where = array();

    if (!empty($values['filter_section'])) {
        $where[] = "Section = '".doSlash($values['filter_section'])."'";
    }

    if (!empty($values['filter_category'])) {
        $where[] = "(Category1 = '".doSlash($values['filter_category'])."' OR Category2 = '".doSlash($values['filter_category'])."')";
    }

    if (!empty($values['filter_custom_field']) && preg_match('/^custom_\d+$/', $values['filter_custom_field']) && $values['filter_custom_value'] !== '') {
        $col = $values['filter_custom_field'];
        $esc = doSlash($values['filter_custom_value']);

        // Token-exact if the field is a pipe-delimited list (same
        // distinction all_search_replace_contains() makes for search),
        // substring otherwise -- expressed in SQL here since it's part
        // of a WHERE clause rather than a post-fetch PHP check.
        if (all_search_replace_is_list_column('textpattern', $col)) {
            $delimiter = $GLOBALS['all_search_replace_list_delimiter'];
            $where[] = "(`".doSlash($col)."` = '".$esc."'"
                ." OR `".doSlash($col)."` LIKE '".$esc.$delimiter."%'"
                ." OR `".doSlash($col)."` LIKE '%".$delimiter.$esc."'"
                ." OR `".doSlash($col)."` LIKE '%".$delimiter.$esc.$delimiter."%')";
        } else {
            $where[] = "`".doSlash($col)."` LIKE '%".$esc."%'";
        }
    }

    return array($where, $values);
}

function all_search_replace_filters_active($values)
{
    if (!empty($values['filter_section']) || !empty($values['filter_category'])) {
        return true;
    }

    return !empty($values['filter_custom_field']) && $values['filter_custom_value'] !== '';
}

// Shared filter fieldset markup -- used by both the search form and the
// bulk-assignment list form. $values is the array all_search_replace_read_filters()
// returns, so a form re-submitted with an error still shows the scope
// the person had set.
function all_search_replace_render_filter_fields($values)
{
    $sections = all_search_replace_filter_sections();
    $categories = all_search_replace_filter_categories();
    $custom_cols = all_search_replace_custom_columns();

    echo n.'<fieldset><legend>Restrict to (optional)</legend>';

    echo n.'<p><label for="asr_filter_section">Section</label> '
        .n.'<select id="asr_filter_section" name="filter_section"><option value="">Any</option>';

    foreach ($sections as $s) {
        $sel = ($values['filter_section'] === $s['name']) ? ' selected="selected"' : '';
        echo n.'<option value="'.txpspecialchars($s['name']).'"'.$sel.'>'.txpspecialchars($s['title']).'</option>';
    }

    echo n.'</select></p>';

    echo n.'<p><label for="asr_filter_category">Category</label> '
        .n.'<select id="asr_filter_category" name="filter_category"><option value="">Any</option>';

    foreach ($categories as $c) {
        $sel = ($values['filter_category'] === $c['name']) ? ' selected="selected"' : '';
        echo n.'<option value="'.txpspecialchars($c['name']).'"'.$sel.'>'.txpspecialchars($c['title']).'</option>';
    }

    echo n.'</select></p>';

    echo n.'<p><label for="asr_filter_custom_field">Custom field</label> '
        .n.'<select id="asr_filter_custom_field" name="filter_custom_field"><option value="">Any</option>';

    foreach ($custom_cols as $col) {
        $sel = ($values['filter_custom_field'] === $col) ? ' selected="selected"' : '';
        echo n.'<option value="'.txpspecialchars($col).'"'.$sel.'>'.txpspecialchars(all_search_replace_display_column('textpattern', $col)).'</option>';
    }

    echo n.'</select> '
        .n.'<label for="asr_filter_custom_value" class="txp-accessibility">Custom field value</label>'
        .n.'<input type="text" id="asr_filter_custom_value" name="filter_custom_value" value="'.txpspecialchars($values['filter_custom_value']).'" placeholder="value" size="20" />'
        .n.'</p>';

    echo n.'</fieldset>';
}

// -----------------------------------------------------------------------
// Audit log
//
// Created lazily (CREATE TABLE IF NOT EXISTS on first use) rather than via
// a plugin_lifecycle 'installed' callback -- this plugin is being edited
// in place through plugin_cache_dir, not reinstalled through the admin UI
// each time, so a lifecycle hook would never fire again after the first
// install and the table would silently never get created. Doubles as the
// source list for any legacy custom-field redirects entries
// -----------------------------------------------------------------------

function all_search_replace_ensure_log_table()
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    $ensured = true;

    try {
        safe_query(
            "CREATE TABLE IF NOT EXISTS ".safe_pfx('all_search_replace_log')." ("
            ."id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, "
            ."changed_at DATETIME NOT NULL, "
            ."changed_by VARCHAR(64) NOT NULL DEFAULT '', "
            ."`table` VARCHAR(64) NOT NULL, "
            ."`column` VARCHAR(64) NOT NULL, "
            ."row_key VARCHAR(255) NOT NULL, "
            ."old_value MEDIUMTEXT, "
            ."new_value MEDIUMTEXT, "
            ."search_term VARCHAR(255) NOT NULL DEFAULT '', "
            ."replace_with VARCHAR(255) NOT NULL DEFAULT ''"
            .") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        // If this fails, logging is skipped for this request -- not fatal
        // to the replace itself, which has already happened by the time
        // this is called.
    }
}

// Logs one changed column. Called once per column actually written --
// do_replace() calls this for the main column and, when applicable, again
// for its html-mirror column, so each gets its own row here.
function all_search_replace_log_change($table, $column, $id, $old, $new, $term, $replace_with)
{
    all_search_replace_ensure_log_table();

    $user = isset($GLOBALS['txp_user']) ? (string) $GLOBALS['txp_user'] : '';

    try {
        safe_insert(
            'all_search_replace_log',
            "changed_at = now(), "
            ."changed_by = '".doSlash($user)."', "
            ."`table` = '".doSlash($table)."', "
            ."`column` = '".doSlash($column)."', "
            ."row_key = '".doSlash($id)."', "
            ."old_value = '".doSlash($old)."', "
            ."new_value = '".doSlash($new)."', "
            ."search_term = '".doSlash($term)."', "
            ."replace_with = '".doSlash($replace_with)."'"
        );
    } catch (Throwable $e) {
        // The DB write already succeeded by the time this runs -- a
        // logging failure shouldn't be reported as the replace failing.
    }
}

function all_search_replace_render_log()
{
    all_search_replace_ensure_log_table();

    all_search_replace_page_open();

    all_search_replace_criteria_open();
    all_search_replace_back_link();
    echo n.'<h4>Change log</h4>';
    all_search_replace_criteria_close();

    try {
        $rows = safe_rows(
            '*',
            'all_search_replace_log',
            '1 = 1 ORDER BY id DESC LIMIT 200'
        );
    } catch (Throwable $e) {
        $rows = array();
    }

    all_search_replace_results_open();

    if (!$rows) {
        echo n.'<p>No changes logged yet.</p>';
        all_search_replace_results_close();
        all_search_replace_page_close();
        return;
    }

    echo n.'<header class="all_search_replace_results_header">'
        .n.'<p>Most recent 200 change(s), newest first:</p>'
        .n.'</header>';

    echo n.'<table><thead><tr>'
        .n.'<th>When</th><th>By</th><th>Table</th><th>Column</th><th>Row</th>'
        .n.'<th>Search</th><th>Replace</th><th>Old value</th><th>New value</th>'
        .n.'</tr></thead><tbody>';

    foreach ($rows as $r) {
        echo n.'<tr>'
            .n.'<td>'.txpspecialchars($r['changed_at']).'</td>'
            .n.'<td>'.txpspecialchars($r['changed_by']).'</td>'
            .n.'<td>'.txpspecialchars($r['table']).'</td>'
            .n.'<td>'.txpspecialchars(all_search_replace_display_column($r['table'], $r['column'])).'</td>'
            .n.'<td>'.txpspecialchars($r['row_key']).'</td>'
            .n.'<td>'.txpspecialchars($r['search_term']).'</td>'
            .n.'<td>'.txpspecialchars($r['replace_with']).'</td>'
            .n.'<td>'.txpspecialchars(mb_substr((string) $r['old_value'], 0, 120)).'</td>'
            .n.'<td>'.txpspecialchars(mb_substr((string) $r['new_value'], 0, 120)).'</td>'
            .n.'</tr>';
    }

    echo n.'</tbody></table>';
    all_search_replace_results_close();
    all_search_replace_page_close();
}

// -----------------------------------------------------------------------
// Results rendering (search step) -- ticking rows here and submitting
// hands off to the preview step, nothing is written from this form.
// -----------------------------------------------------------------------

function all_search_replace_render_results($term, $case_sensitive, $results)
{
    all_search_replace_results_open();

    if (!$results) {
        echo n.'<h4>No matches for &#8220;'.txpspecialchars($term).'&#8221;.</h4>';
        all_search_replace_results_close();
        return;
    }

    echo n.'<h4>'.count($results).' match(es) for &#8220;'.txpspecialchars($term).'&#8221;:</h4>';

    echo n.'<form method="post" action="index.php">'
        .eInput('all_search_replace')
        .sInput('all_search_replace_preview')
        .hInput('term', $term)
        .hInput('case_sensitive', $case_sensitive ? '1' : '')
        .n.'<header class="all_search_replace_results_header">'

        .n.'<p><label for="asr_replace_with">Replace with</label> '
        .n.'<input type="text" id="asr_replace_with" name="replace_with" value="" size="40" /></p>'
        .n.'<p><input type="submit" class="publish" value="Preview replacement for checked rows" /></p>'
        .n.'</header>';

    echo n.'<table>'
        .n.'<thead><tr>'
        .n.'<th class="txp-list-col-multi-edit" scope="col">'
        .n.'<label for="select_all" class="txp-accessibility">Toggle all/none selected</label>'
        .n.'<input name="select_all" id="select_all" type="checkbox" value="0" />'
        .n.'</th>'
        .n.'<th>Table</th><th>Column</th><th>Row</th><th>Status</th><th>Section</th><th>Category</th><th>Context</th><th></th>'
        .n.'</tr></thead><tbody>';

    foreach ($results as $r) {
        $link = all_search_replace_edit_link($r['table'], $r['id']);
        $target = all_search_replace_encode_target($r['table'], $r['column'], $r['id']);

        echo n.'<tr>'
            .n.'<td><input type="checkbox" name="targets[]" value="'.txpspecialchars($target).'" /></td>'
            .n.'<td>'.txpspecialchars($r['table']).'</td>'
            .n.'<td>'.txpspecialchars(all_search_replace_display_column($r['table'], $r['column'])).'</td>'
            // Not intval()'d here: the row identifier is a plain int for
            // most tables but a string (e.g. section name) for others.
            .n.'<td>'.txpspecialchars((string) $r['id']).'</td>'
            .all_search_replace_context_cells($r['table'], $r['id'])
            .n.'<td>'.$r['context'].'</td>'
            .n.'<td>'.($link ? '<a href="'.txpspecialchars($link).'" target="_blank">edit &#8594;</a>' : '').'</td>'
            .n.'</tr>';
    }

    echo n.'</tbody></table>'
        .n.'</form>';

    all_search_replace_select_all_script('targets[]');

    all_search_replace_results_close();
}

// -----------------------------------------------------------------------
// Step: preview (read-only -- re-fetches current values, nothing written)
// -----------------------------------------------------------------------

function all_search_replace_preview()
{
    $term = ps('term');
    $case_sensitive = (bool) ps('case_sensitive');
    $replace_with = ps('replace_with');
    $targets = ps('targets');

    all_search_replace_page_open();

    if ($term === '' || $term === false || !is_array($targets) || !$targets) {
        all_search_replace_criteria_open();
        all_search_replace_back_link();
        echo n.'<p>Nothing selected to preview.</p>';
        all_search_replace_criteria_close();
        all_search_replace_page_close();
        return;
    }

    $rows = array();
    $valid_targets = array();

    foreach ($targets as $encoded) {
        $t = all_search_replace_decode_target($encoded);

        if (!$t || !isset($GLOBALS['all_search_replace_tables'][$t['table']])) {
            continue;
        }

        $conf = $GLOBALS['all_search_replace_tables'][$t['table']];
        $current = all_search_replace_fetch_value($t['table'], $conf['key'], $t['id'], $t['column']);

        if ($current === false) {
            $rows[] = $t + array('status' => 'missing');
            continue;
        }

        if (!all_search_replace_contains($t['table'], $t['column'], $current, $term, $case_sensitive)) {
            $rows[] = $t + array('status' => 'changed');
            continue;
        }

        $new = all_search_replace_apply($t['table'], $t['column'], $current, $term, $replace_with, $case_sensitive);
        list($old_snip, $new_snip) = all_search_replace_diff_context($current, $new);

        $rows[] = $t + array('status' => 'ok', 'old_snippet' => $old_snip, 'new_snippet' => $new_snip);
        $valid_targets[] = $encoded;
    }

    // Left column: back link, term summary, and (if there's anything left
    // to confirm) the write form -- kept here rather than after the table
    // so it stays visible via the sticky positioning while scrolling a
    // long before/after list, same as the "Replace with" controls do on
    // the search-results step.
    all_search_replace_criteria_open();
    all_search_replace_back_link();
    echo n.'<h4>Preview: &#8220;'.txpspecialchars($term).'&#8221; &#8594; &#8220;'.txpspecialchars($replace_with).'&#8221;</h4>';

    if ($valid_targets) {
        $count = count($valid_targets);

        echo n.'<form method="post" action="index.php">'
            .eInput('all_search_replace')
            .sInput('all_search_replace_do_replace')
            .hInput('term', $term)
            .hInput('case_sensitive', $case_sensitive ? '1' : '')
            .hInput('replace_with', $replace_with)
            .n.'<input type="hidden" name="_txp_token" value="'.form_token().'" />';

        foreach ($valid_targets as $encoded) {
            echo n.'<input type="hidden" name="targets[]" value="'.txpspecialchars($encoded).'" />';
        }

        echo n.'<p><input type="submit" class="publish" '
            .'value="Confirm &amp; replace ('.$count.' row(s))" '
            .'onclick="return confirm(\'This writes directly to the database for '.$count.' row(s). Continue?\');" /></p>'
            .n.'</form>';
    } elseif ($rows) {
        echo n.'<p>Nothing left to replace -- every selected row was skipped below.</p>';
    }

    all_search_replace_criteria_close();

    if (!$rows) {
        all_search_replace_results_open();
        echo n.'<p>Nothing to preview.</p>';
        all_search_replace_results_close();
        all_search_replace_page_close();
        return;
    }

    all_search_replace_results_open();
    echo n.'<table><thead><tr>'
        .n.'<th>Table</th><th>Column</th><th>Row</th><th>Status</th><th>Section</th><th>Category</th><th>Before</th><th>After</th>'
        .n.'</tr></thead><tbody>';

    foreach ($rows as $r) {
        echo n.'<tr>'
            .n.'<td>'.txpspecialchars($r['table']).'</td>'
            .n.'<td>'.txpspecialchars(all_search_replace_display_column($r['table'], $r['column'])).'</td>'
            .n.'<td>'.txpspecialchars((string) $r['id']).'</td>'
            .all_search_replace_context_cells($r['table'], $r['id']);

        if ($r['status'] === 'missing') {
            echo n.'<td colspan="2"><em>Row no longer exists -- skipped.</em></td>';
        } elseif ($r['status'] === 'changed') {
            echo n.'<td colspan="2"><em>Value changed since search and no longer contains the term -- skipped.</em></td>';
        } else {
            echo n.'<td>'.$r['old_snippet'].'</td><td>'.$r['new_snippet'].'</td>';
        }

        echo n.'</tr>';
    }

    echo n.'</tbody></table>';
    all_search_replace_results_close();
    all_search_replace_page_close();
}

// -----------------------------------------------------------------------
// Step: do_replace (writes -- token-checked by bouncer() before this runs)
// -----------------------------------------------------------------------

function all_search_replace_do_replace()
{
    $term = ps('term');
    $case_sensitive = (bool) ps('case_sensitive');
    $replace_with = ps('replace_with');
    $targets = ps('targets');

    all_search_replace_page_open();

    if ($term === '' || $term === false || !is_array($targets) || !$targets) {
        all_search_replace_criteria_open();
        all_search_replace_back_link();
        echo n.'<p>Nothing to replace.</p>';
        all_search_replace_criteria_close();
        all_search_replace_page_close();
        return;
    }

    $done = array();
    $skipped = array();
    $touched_articles = false;

    foreach ($targets as $encoded) {
        $t = all_search_replace_decode_target($encoded);

        if (!$t || !isset($GLOBALS['all_search_replace_tables'][$t['table']])) {
            continue;
        }

        $table = $t['table'];
        $column = $t['column'];
        $id = $t['id'];
        $conf = $GLOBALS['all_search_replace_tables'][$table];
        $key_col = $conf['key'];

        // Re-fetch and re-check one more time -- never trust the preview
        // step's snapshot either. A concurrent edit between preview and
        // confirm means this row is silently skipped, not overwritten.
        $current = all_search_replace_fetch_value($table, $key_col, $id, $column);

        if ($current === false) {
            $skipped[] = array('table' => $table, 'column' => $column, 'id' => $id, 'reason' => 'row no longer exists');
            continue;
        }

        if (!all_search_replace_contains($table, $column, $current, $term, $case_sensitive)) {
            $skipped[] = array('table' => $table, 'column' => $column, 'id' => $id, 'reason' => 'no longer contains the term');
            continue;
        }

        $new = all_search_replace_apply($table, $column, $current, $term, $replace_with, $case_sensitive);

        $set_clauses = array(
            "`".doSlash($column)."` = '".doSlash($new)."'",
        );
        $old_values = array($column => $current);
        $new_values = array($column => $new);

        // Keep a Textile source column and its rendered cache in sync in
        // the same UPDATE, per all_search_replace_html_mirrors above.
        // (Mirror columns are always native textpattern columns, never a
        // custom_* list field, so this always takes the plain-text path.)
        if (!empty($GLOBALS['all_search_replace_html_mirrors'][$table][$column])) {
            $mirror_col = $GLOBALS['all_search_replace_html_mirrors'][$table][$column];
            $mirror_val = all_search_replace_fetch_value($table, $key_col, $id, $mirror_col);

            if ($mirror_val !== false) {
                $mirror_new = all_search_replace_apply($table, $mirror_col, $mirror_val, $term, $replace_with, $case_sensitive);
                $set_clauses[] = "`".doSlash($mirror_col)."` = '".doSlash($mirror_new)."'";
                $old_values[$mirror_col] = $mirror_val;
                $new_values[$mirror_col] = $mirror_new;
            }
        }

        // This write bypasses TXP's normal article-save path entirely
        // (a direct safe_update(), not the article editor), so LastMod/
        // LastModID never get touched the way they would through that
        // path -- left alone, a search-replaced article's "Last
        // Modified" stays stale in the article list. Folded into the
        // same UPDATE rather than a second query. Not logged to the
        // audit table: these are administrative, not part of the
        // search/replace the log records.
        if ($table === 'textpattern') {
            global $txp_user;
            $set_clauses[] = "`LastMod` = NOW()";
            $set_clauses[] = "`LastModID` = '".doSlash((string) $txp_user)."'";
        }

        try {
            safe_update(
                doSlash($table),
                implode(', ', $set_clauses),
                "`".doSlash($key_col)."` = '".doSlash($id)."'"
            );
        } catch (Throwable $e) {
            $skipped[] = array('table' => $table, 'column' => $column, 'id' => $id, 'reason' => $e->getMessage());
            continue;
        }

        if ($table === 'textpattern') {
            $touched_articles = true;
        }

        foreach ($new_values as $changed_col => $changed_new) {
            all_search_replace_log_change(
                $table,
                $changed_col,
                $id,
                $old_values[$changed_col],
                $changed_new,
                $term,
                $replace_with
            );
        }

        $done[] = array('table' => $table, 'column' => $column, 'id' => $id, 'old' => $old_values, 'new' => $new_values);
    }

    // Site-wide "last modified" marker TXP's own article-save path bumps
    // on every change, used for output caching / RSS conditional-GET --
    // called once after the loop rather than per row, since it's a
    // single global value, not per-article state.
    if ($touched_articles && function_exists('update_lastmod')) {
        update_lastmod();
    }

    // Left column: back link, summary and skip list -- same sticky
    // treatment as the criteria/form panel on the search and preview
    // steps, kept here rather than after the results table.
    all_search_replace_criteria_open();
    all_search_replace_back_link();
    echo n.'<h4>Replace complete: &#8220;'.txpspecialchars($term).'&#8221; &#8594; &#8220;'.txpspecialchars($replace_with).'&#8221;</h4>';

    if ($done) {
        echo n.'<p>'.count($done).' row(s) updated:</p>';
        echo n.'<p>Recorded in the <a href="index.php?event=all_search_replace&amp;step=all_search_replace_render_log">change log</a> '
            .'-- use it as the source list for the Legacy collection redirects entries.</p>';
    } else {
        echo n.'<p>Nothing was updated.</p>';
    }

    if ($skipped) {
        echo n.'<p>'.count($skipped).' row(s) skipped:</p><ul>';

        foreach ($skipped as $s) {
            echo n.'<li>'.txpspecialchars($s['table'].'.'.$s['column'].' ('.$s['id'].'): '.$s['reason']).'</li>';
        }

        echo n.'</ul>';
    }

    all_search_replace_criteria_close();

    all_search_replace_results_open();

    if ($done) {
        echo n.'<table class="txp-list"><thead><tr><th>Table</th><th>Row</th><th>Column(s)</th></tr></thead><tbody>';

        foreach ($done as $d) {
            echo n.'<tr>'
                .n.'<td>'.txpspecialchars($d['table']).'</td>'
                .n.'<td>'.txpspecialchars((string) $d['id']).'</td>'
                .n.'<td>'.txpspecialchars(implode(', ', array_keys($d['new']))).'</td>'
                .n.'</tr>';
        }

        echo n.'</tbody></table>';
    } else {
        echo n.'<p>Nothing to show.</p>';
    }

    all_search_replace_results_close();
    all_search_replace_page_close();
}

// -----------------------------------------------------------------------
// Bulk field assignment (roadmap #2)
//
// Distinct from find/replace: there's no single old value to search for
// across a set of rows with many different existing values -- this is
// "for every article matching these filters, SET custom_N = 'x'" (or
// Category1/Category2), a straight assignment rather than a
// find-and-replace within the existing value. Filter-only, no search
// term, reusing the same filter builder as the text-search step. Same
// list -> preview -> confirm shape as search -> preview -> do_replace,
// same re-fetch-before-writing and audit logging.
// -----------------------------------------------------------------------

function all_search_replace_bulk_targets()
{
    $targets = array();

    foreach (all_search_replace_custom_columns() as $col) {
        $targets[$col] = all_search_replace_display_column('textpattern', $col);
    }

    $targets['Category1'] = 'Category (primary)';
    $targets['Category2'] = 'Category (secondary)';

    return $targets;
}

function all_search_replace_bulk_query($filter_where)
{
    if (!$filter_where) {
        return array();
    }

    try {
        return safe_rows(
            'ID, Title, Status, Section, Category1, Category2',
            'textpattern',
            implode(' AND ', $filter_where).' ORDER BY Title'
        );
    } catch (Throwable $e) {
        return array();
    }
}

// Bulk modes. For a pipe-delimited list column (glz multi-select etc.) a
// straight overwrite throws away every existing choice, which is almost
// never what's wanted -- 'add' merges the new token(s) into what's there,
// 'remove' takes them out, 'replace' is the old overwrite. Free-text and
// category columns have no tokens, so they're always 'replace'.
function all_search_replace_bulk_modes()
{
    return array(
        'add' => 'Add to existing values',
        'remove' => 'Remove from existing values',
        'replace' => 'Replace entire value',
    );
}

function all_search_replace_bulk_mode($target, $mode)
{
    if (!all_search_replace_is_list_column('textpattern', $target)) {
        return 'replace';
    }

    $modes = all_search_replace_bulk_modes();

    return isset($modes[$mode]) ? $mode : 'add';
}

function all_search_replace_bulk_compute($target, $current, $value, $mode)
{
    if ($mode === 'replace' && !all_search_replace_is_list_column('textpattern', $target)) {
        return $value;
    }

    $delimiter = $GLOBALS['all_search_replace_list_delimiter'];
    $not_empty = function ($t) { return $t !== ''; };
    $tokens = array_values(array_filter(explode($delimiter, (string) $current), $not_empty));
    $given = array_values(array_filter(array_map('trim', explode($delimiter, (string) $value)), $not_empty));

    if ($mode === 'replace') {
        return implode($delimiter, $given);
    }

    $lower_given = array_map('mb_strtolower', $given);

    if ($mode === 'remove') {
        $tokens = array_filter($tokens, function ($t) use ($lower_given) {
            return !in_array(mb_strtolower($t), $lower_given, true);
        });

        return implode($delimiter, $tokens);
    }

    // add -- append only tokens not already present (case-insensitive)
    $lower_tokens = array_map('mb_strtolower', $tokens);

    foreach ($given as $i => $g) {
        if (!in_array($lower_given[$i], $lower_tokens, true)) {
            $tokens[] = $g;
            $lower_tokens[] = $lower_given[$i];
        }
    }

    return implode($delimiter, $tokens);
}

function all_search_replace_bulk_heading($prefix, $target, $value, $mode)
{
    $col = txpspecialchars(all_search_replace_display_column('textpattern', $target));
    $val = '&#8220;'.txpspecialchars($value).'&#8221;';

    switch ($mode) {
        case 'add':
            return $prefix.': add '.$val.' to '.$col;
        case 'remove':
            return $prefix.': remove '.$val.' from '.$col;
        default:
            return $prefix.': set '.$col.' to '.$val;
    }
}

// -----------------------------------------------------------------------
// Step: bulk_list (read-only) -- filters only, no term. Lists matching
// articles and, below the list, the target-field/value picker that
// submits the checked rows on to bulk_preview.
// -----------------------------------------------------------------------

function all_search_replace_bulk_list()
{
    list($filter_where, $filter_values) = all_search_replace_read_filters();

    all_search_replace_page_open();

    echo n.'<form method="get" action="index.php" class="all_search_replace_criteria">'
        .eInput('all_search_replace')
        .sInput('all_search_replace_bulk_list')
        .n.'<p><a href="index.php?event=all_search_replace">&#8592; Back to search</a></p>'
        .n.'<h4>Bulk field assignment</h4>';

    all_search_replace_render_filter_fields($filter_values);

    echo n.'<p><input type="submit" class="publish" value="List matching articles" /></p>'
        .n.'</form>';

    if (all_search_replace_filters_active($filter_values)) {
        $articles = all_search_replace_bulk_query($filter_where);
        all_search_replace_render_bulk_list($articles);
    } else {
        all_search_replace_results_open();
        echo n.'<p>Set at least one filter above to list matching articles.</p>';
        all_search_replace_results_close();
    }

    all_search_replace_page_close();
}

function all_search_replace_render_bulk_list($articles)
{
    all_search_replace_results_open();

    if (!$articles) {
        echo n.'<p>No articles match that filter.</p>';
        all_search_replace_results_close();
        return;
    }

    echo n.'<h4>'.count($articles).' matching article(s):</h4>';

    $targets = all_search_replace_bulk_targets();

    echo n.'<form method="post" action="index.php">'
        .eInput('all_search_replace')
        .sInput('all_search_replace_bulk_preview')
        .n.'<header class="all_search_replace_results_header">'
        .n.'<p><label for="asr_bulk_target">Set field</label> '
        .n.'<select id="asr_bulk_target" name="bulk_target">';

    foreach ($targets as $col => $label) {
        echo n.'<option value="'.txpspecialchars($col).'">'.txpspecialchars($label).'</option>';
    }

    echo n.'</select></p>'
        .n.'<p><label for="asr_bulk_mode">Mode</label> '
        .n.'<select id="asr_bulk_mode" name="bulk_mode">';

    foreach (all_search_replace_bulk_modes() as $m => $label) {
        echo n.'<option value="'.$m.'">'.txpspecialchars($label).'</option>';
    }

    echo n.'</select><br /><small>Add/Remove apply to multi-value (list) fields only &#8212; other fields are always replaced.</small></p>'
        .n.'<p><label for="asr_bulk_value">Value</label> '
        .n.'<input type="text" id="asr_bulk_value" name="bulk_value" value="" size="30" />'
        .n.'<br /><small>Separate multiple values with | (e.g. External|Facade).</small></p>'
        .n.'<p><input type="submit" class="publish" value="Preview assignment for checked rows" /></p>'
        .n.'</header>';

    echo n.'<table>'
        .n.'<thead><tr>'
        .n.'<th class="txp-list-col-multi-edit" scope="col">'
        .n.'<label for="select_all" class="txp-accessibility">Toggle all/none selected</label>'
        .n.'<input name="select_all" id="select_all" type="checkbox" value="0" />'
        .n.'</th>'
        .n.'<th>ID</th><th>Title</th><th>Status</th><th>Section</th><th>Category</th>'
        .n.'</tr></thead><tbody>';

    foreach ($articles as $a) {
        $link = all_search_replace_edit_link('textpattern', $a['ID']);
        $category = implode(', ', array_filter(array($a['Category1'], $a['Category2'])));

        echo n.'<tr>'
            .n.'<td><input type="checkbox" name="ids[]" value="'.intval($a['ID']).'" /></td>'
            .n.'<td>'.intval($a['ID']).($link ? ' <a href="'.txpspecialchars($link).'" target="_blank">edit &#8594;</a>' : '').'</td>'
            .n.'<td>'.txpspecialchars($a['Title']).'</td>'
            .n.'<td>'.txpspecialchars(all_search_replace_status_label($a['Status'])).'</td>'
            .n.'<td>'.txpspecialchars($a['Section']).'</td>'
            .n.'<td>'.txpspecialchars($category).'</td>'
            .n.'</tr>';
    }

    echo n.'</tbody></table>'
        .n.'</form>';

    all_search_replace_select_all_script('ids[]');

    all_search_replace_results_close();
}

// -----------------------------------------------------------------------
// Step: bulk_preview (read-only -- re-fetches current values)
// -----------------------------------------------------------------------

function all_search_replace_bulk_preview()
{
    $target = ps('bulk_target');
    $value = ps('bulk_value');
    $ids = ps('ids');
    $mode = all_search_replace_bulk_mode($target, ps('bulk_mode'));

    all_search_replace_page_open();

    if ($target === '' || $target === false || !preg_match('/^(custom_\d+|Category[12])$/', $target) || !is_array($ids) || !$ids) {
        all_search_replace_criteria_open();
        all_search_replace_back_link();
        echo n.'<p>Nothing selected to preview.</p>';
        all_search_replace_criteria_close();
        all_search_replace_page_close();
        return;
    }

    $rows = array();
    $valid_ids = array();

    foreach ($ids as $id) {
        $id = intval($id);
        $current = all_search_replace_fetch_value('textpattern', 'ID', $id, $target);

        if ($current === false) {
            $rows[] = array('id' => $id, 'status' => 'missing');
            continue;
        }

        $new = all_search_replace_bulk_compute($target, $current, $value, $mode);

        if ($new === (string) $current) {
            $rows[] = array('id' => $id, 'status' => 'unchanged', 'old' => $current);
            continue;
        }

        $rows[] = array('id' => $id, 'status' => 'ok', 'old' => $current, 'new' => $new);
        $valid_ids[] = $id;
    }

    all_search_replace_criteria_open();
    all_search_replace_back_link();
    echo n.'<h4>'.all_search_replace_bulk_heading('Bulk assign', $target, $value, $mode).'</h4>';

    if ($valid_ids) {
        $count = count($valid_ids);

        echo n.'<form method="post" action="index.php">'
            .eInput('all_search_replace')
            .sInput('all_search_replace_bulk_assign')
            .hInput('bulk_target', $target)
            .hInput('bulk_value', $value)
            .hInput('bulk_mode', $mode)
            .n.'<input type="hidden" name="_txp_token" value="'.form_token().'" />';

        foreach ($valid_ids as $id) {
            echo n.'<input type="hidden" name="ids[]" value="'.intval($id).'" />';
        }

        echo n.'<p><input type="submit" class="publish" '
            .'value="Confirm &amp; assign ('.$count.' row(s))" '
            .'onclick="return confirm(\'This writes directly to the database for '.$count.' row(s). Continue?\');" /></p>'
            .n.'</form>';
    } else {
        echo n.'<p>Nothing left to assign -- every selected row was skipped below.</p>';
    }

    all_search_replace_criteria_close();

    all_search_replace_results_open();
    echo n.'<table><thead><tr>'
        .n.'<th>ID</th><th>Before</th><th>After</th>'
        .n.'</tr></thead><tbody>';

    foreach ($rows as $r) {
        echo n.'<tr><td>'.intval($r['id']).'</td>';

        if ($r['status'] === 'missing') {
            echo n.'<td colspan="2"><em>Row no longer exists -- skipped.</em></td>';
        } elseif ($r['status'] === 'unchanged') {
            echo n.'<td>'.txpspecialchars((string) $r['old']).'</td><td><em>No change -- skipped.</em></td>';
        } else {
            echo n.'<td>'.txpspecialchars((string) $r['old']).'</td><td>'.txpspecialchars((string) $r['new']).'</td>';
        }

        echo n.'</tr>';
    }

    echo n.'</tbody></table>';
    all_search_replace_results_close();
    all_search_replace_page_close();
}

// -----------------------------------------------------------------------
// Step: bulk_assign (writes -- token-checked by bouncer() before this runs)
// -----------------------------------------------------------------------

function all_search_replace_bulk_assign()
{
    global $txp_user;

    $target = ps('bulk_target');
    $value = ps('bulk_value');
    $ids = ps('ids');
    $mode = all_search_replace_bulk_mode($target, ps('bulk_mode'));

    all_search_replace_page_open();

    if ($target === '' || $target === false || !preg_match('/^(custom_\d+|Category[12])$/', $target) || !is_array($ids) || !$ids) {
        all_search_replace_criteria_open();
        all_search_replace_back_link();
        echo n.'<p>Nothing to assign.</p>';
        all_search_replace_criteria_close();
        all_search_replace_page_close();
        return;
    }

    $done = array();
    $skipped = array();

    foreach ($ids as $id) {
        $id = intval($id);

        // Re-fetch one more time -- never trust the preview snapshot,
        // same rule as do_replace().
        $current = all_search_replace_fetch_value('textpattern', 'ID', $id, $target);

        if ($current === false) {
            $skipped[] = array('id' => $id, 'reason' => 'row no longer exists');
            continue;
        }

        // Computed from the freshly fetched value, not the preview's.
        $new = all_search_replace_bulk_compute($target, $current, $value, $mode);

        if ($new === (string) $current) {
            $skipped[] = array('id' => $id, 'reason' => 'no change needed');
            continue;
        }

        try {
            safe_update(
                'textpattern',
                "`".doSlash($target)."` = '".doSlash($new)."', `LastMod` = NOW(), `LastModID` = '".doSlash((string) $txp_user)."'",
                "`ID` = '".doSlash($id)."'"
            );
        } catch (Throwable $e) {
            $skipped[] = array('id' => $id, 'reason' => $e->getMessage());
            continue;
        }

        // There's no search term for a bulk assignment -- 'bulk assign'
        // stands in for it in the log so the row stays traceable to what
        // ran, same purpose that column serves for do_replace()'s rows.
        all_search_replace_log_change('textpattern', $target, $id, $current, $new, 'bulk '.$mode, $value);

        $done[] = array('id' => $id, 'old' => $current, 'new' => $new);
    }

    if ($done && function_exists('update_lastmod')) {
        update_lastmod();
    }

    all_search_replace_criteria_open();
    all_search_replace_back_link();
    echo n.'<h4>'.all_search_replace_bulk_heading('Bulk assign complete', $target, $value, $mode).'</h4>';

    if ($done) {
        echo n.'<p>'.count($done).' row(s) updated:</p>';
        echo n.'<p>Recorded in the <a href="index.php?event=all_search_replace&amp;step=all_search_replace_render_log">change log</a>.</p>';
    } else {
        echo n.'<p>Nothing was updated.</p>';
    }

    if ($skipped) {
        echo n.'<p>'.count($skipped).' row(s) skipped:</p><ul>';

        foreach ($skipped as $s) {
            echo n.'<li>ID '.intval($s['id']).': '.txpspecialchars($s['reason']).'</li>';
        }

        echo n.'</ul>';
    }

    all_search_replace_criteria_close();

    all_search_replace_results_open();

    if ($done) {
        echo n.'<table class="txp-list"><thead><tr><th>ID</th><th>Old value</th><th>New value</th></tr></thead><tbody>';

        foreach ($done as $d) {
            echo n.'<tr>'
                .n.'<td>'.intval($d['id']).'</td>'
                .n.'<td>'.txpspecialchars((string) $d['old']).'</td>'
                .n.'<td>'.txpspecialchars((string) $d['new']).'</td>'
                .n.'</tr>';
        }

        echo n.'</tbody></table>';
    } else {
        echo n.'<p>Nothing to show.</p>';
    }

    all_search_replace_results_close();
    all_search_replace_page_close();
}

// -----------------------------------------------------------------------
// ROADMAP -- both items built above. Nothing outstanding right now.
// -----------------------------------------------------------------------
