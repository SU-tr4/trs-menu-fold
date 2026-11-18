<?php
/**
 * Plugin Name: TRS Menu Fold
 * Description: WordPress管理画面の左メニューの並び替え、非表示設定、グループ化（開閉式アコーディオン）を行うプラグインです。
 * Version: 1.2.0
 * Author: TRS
 * License: GPL2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRS_Menu_Fold {

    private $option_name = 'trsmf_menu_settings';
    private $original_menu = array();

    public function __construct() {
        // メニューの取得とカスタマイズ適用（優先度999で他のプラグインのメニュー追加後に実行）
        add_action( 'admin_menu', array( $this, 'process_admin_menu' ), 999 );
        
        // 設定ページの追加
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        
        // 設定保存処理
        add_action( 'admin_init', array( $this, 'save_settings' ) );
        
        // 管理画面用のCSSとJSの読み込み
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

        // 公式リポジトリからの誤認アップデート通知を完全に除外
        add_filter( 'site_transient_update_plugins', array( $this, 'disable_plugin_updates' ) );
    }

    /**
     * 公式リポジトリからの自動更新通知を除外
     */
    public function disable_plugin_updates( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }
        $plugin_file = plugin_basename( __FILE__ );
        if ( isset( $transient->checked[ $plugin_file ] ) ) {
            unset( $transient->checked[ $plugin_file ] );
        }
        return $transient;
    }

    /**
     * 設定ページを「設定」サブメニューに追加
     */
    public function add_settings_page() {
        add_options_page(
            'TRS Menu Fold',
            'TRS Menu Fold',
            'manage_options',
            'trs-menu-fold',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * 設定画面および全管理画面用のスクリプト・スタイル読み込み
     */
    public function enqueue_admin_assets( $hook ) {
        // 1. 全管理画面でアコーディオン（開閉）機能を動かすためのJS/CSS
        wp_register_style( 'trsmf-global-css', false );
        wp_enqueue_style( 'trsmf-global-css' );
        wp_add_inline_style( 'trsmf-global-css', '
            #adminmenu li.trsmf-group-header > a {
                background-color: rgba(255, 255, 255, 0.08) !important;
                font-weight: bold !important;
                border-left: 3px solid #72aee6;
            }
            #adminmenu li.trsmf-group-header .wp-menu-name::after {
                content: "\f140";
                font-family: dashicons;
                float: right;
                transition: transform 0.2s ease;
            }
            #adminmenu li.trsmf-group-header.trsmf-open .wp-menu-name::after {
                transform: rotate(180deg);
            }
            #adminmenu li.trsmf-grouped-item {
                display: none;
                background-color: rgba(0, 0, 0, 0.2) !important;
                padding-left: 10px !important;
            }
            #adminmenu li.trsmf-grouped-item.trsmf-visible {
                display: block;
            }
        ' );

        wp_register_script( 'trsmf-global-js', false, array( 'jquery' ), false, true );
        wp_enqueue_script( 'trsmf-global-js' );
        wp_add_inline_script( 'trsmf-global-js', '
            jQuery(document).ready(function($) {
                // グループヘッダーのクリックイベント
                $("#adminmenu").on("click", "li.trsmf-group-header > a", function(e) {
                    e.preventDefault();
                    var $header = $(this).parent("li");
                    var groupId = $header.data("trsmf-group");
                    
                    $header.toggleClass("trsmf-open");
                    $("#adminmenu li.trsmf-grouped-item[data-trsmf-parent=\'" + groupId + "\']").toggleClass("trsmf-visible");
                });

                // 現在開いているページがグループ内のメニューだった場合、最初から開いておく
                var $activeGroupedItem = $("#adminmenu li.trsmf-grouped-item.current, #adminmenu li.trsmf-grouped-item.wp-has-current-submenu");
                if ($activeGroupedItem.length > 0) {
                    var parentGroupId = $activeGroupedItem.data("trsmf-parent");
                    $("#adminmenu li.trsmf-group-header[data-trsmf-group=\'" + parentGroupId + "\']").addClass("trsmf-open");
                    $("#adminmenu li.trsmf-grouped-item[data-trsmf-parent=\'" + parentGroupId + "\']").addClass("trsmf-visible");
                }
            });
        ' );

        // 2. プラグイン設定画面専用のJS/CSS（ドラッグ＆ドロップ並び替え用）
        if ( 'settings_page_trs-menu-fold' === $hook ) {
            wp_enqueue_script( 'jquery-ui-sortable' );
            
            wp_register_style( 'trsmf-admin-css', false );
            wp_enqueue_style( 'trsmf-admin-css' );
            wp_add_inline_style( 'trsmf-admin-css', '
                #trsmf-menu-list { max-width: 800px; margin-top: 20px; }
                .trsmf-menu-item {
                    background: #fff;
                    border: 1px solid #ccd0d4;
                    padding: 12px 15px;
                    margin-bottom: 8px;
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    cursor: move;
                    border-radius: 4px;
                    box-shadow: 0 1px 1px rgba(0,0,0,.04);
                }
                .trsmf-menu-item.trsmf-is-group {
                    background: #f0f6fc;
                    border-left: 4px solid #2271b1;
                }
                .trsmf-menu-item.trsmf-is-hidden {
                    opacity: 0.6;
                    background: #f6f7f7;
                }
                .trsmf-item-left { display: flex; align-items: center; gap: 10px; }
                .trsmf-drag-handle { color: #8c8f94; cursor: move; }
                .trsmf-item-title { font-weight: 600; font-size: 14px; }
                .trsmf-item-slug { color: #8c8f94; font-size: 12px; }
                .trsmf-item-controls { display: flex; align-items: center; gap: 15px; }
                .trsmf-group-creator {
                    background: #fff;
                    border: 1px solid #ccd0d4;
                    padding: 15px;
                    max-width: 800px;
                    margin-top: 20px;
                    border-radius: 4px;
                }
            ' );

            wp_register_script( 'trsmf-admin-js', false, array( 'jquery', 'jquery-ui-sortable' ), false, true );
            wp_enqueue_script( 'trsmf-admin-js' );
            wp_add_inline_script( 'trsmf-admin-js', '
                jQuery(document).ready(function($) {
                    $("#trsmf-menu-list").sortable({
                        handle: ".trsmf-drag-handle",
                        placeholder: "ui-state-highlight"
                    });

                    // 新規グループ（開閉ヘッダー）の追加
                    $("#trsmf-add-group-btn").on("click", function() {
                        var groupName = $("#trsmf-new-group-name").val().trim();
                        if (!groupName) {
                            alert("グループ名を入力してください。");
                            return;
                        }
                        var groupId = "trsmf_group_" + Date.now();
                        var html = `
                            <li class="trsmf-menu-item trsmf-is-group" data-slug="${groupId}" data-type="group">
                                <div class="trsmf-item-left">
                                    <span class="dashicons dashicons-menu trsmf-drag-handle"></span>
                                    <span class="dashicons dashicons-category" style="color:#2271b1;"></span>
                                    <input type="text" class="trsmf-group-title-input" value="${escapeHtml(groupName)}" style="font-weight:bold;">
                                    <span class="trsmf-item-slug">(開閉グループ親)</span>
                                </div>
                                <div class="trsmf-item-controls">
                                    <button type="button" class="button trsmf-remove-group-btn" style="color:#d63638;">削除</button>
                                </div>
                            </li>
                        `;
                        $("#trsmf-menu-list").prepend(html);
                        $("#trsmf-new-group-name").val("");
                    });

                    // グループの削除
                    $("#trsmf-menu-list").on("click", ".trsmf-remove-group-btn", function() {
                        $(this).closest("li").remove();
                    });

                    // 非表示チェックボックスの見た目反映
                    $("#trsmf-menu-list").on("change", ".trsmf-hide-checkbox", function() {
                        $(this).closest("li").toggleClass("trsmf-is-hidden", this.checked);
                    });

                    // フォーム送信時にJSON化してhiddenフィールドに格納
                    $("#trsmf-settings-form").on("submit", function() {
                        var items = [];
                        $("#trsmf-menu-list li").each(function() {
                            var $li = $(this);
                            var type = $li.data("type");
                            var slug = $li.data("slug");
                            
                            if (type === "group") {
                                items.push({
                                    type: "group",
                                    slug: slug,
                                    title: $li.find(".trsmf-group-title-input").val(),
                                    hidden: false,
                                    in_group: false
                                });
                            } else {
                                items.push({
                                    type: "menu",
                                    slug: slug,
                                    hidden: $li.find(".trsmf-hide-checkbox").is(":checked"),
                                    in_group: $li.find(".trsmf-ingroup-checkbox").is(":checked")
                                });
                            }
                        });
                        $("#trsmf-settings-data").val(JSON.stringify(items));
                    });

                    function escapeHtml(str) {
                        return str.replace(/[&<>"\']/g, function(m) {
                            return { "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "\'": "&#039;" }[m];
                        });
                    }
                });
            ' );
        }
    }

    /**
     * 実際の左メニューの並び替え・非表示・グループ化を処理
     */
    public function process_admin_menu() {
        global $menu;

        if ( ! is_array( $menu ) ) {
            return;
        }

        // 設定画面で元のメニュー一覧を表示するために保存しておく
        $this->original_menu = $menu;

        $saved_settings = get_option( $this->option_name, array() );
        if ( empty( $saved_settings ) || ! is_array( $saved_settings ) ) {
            return;
        }

        // 現在の $menu をスラッグをキーにした連想配列にマッピング
        $menu_by_slug = array();
        foreach ( $menu as $index => $item ) {
            $slug = $item[2];
            $menu_by_slug[ $slug ] = $item;
        }

        $new_menu = array();
        $position = 10;
        $current_group_id = null;

        // 保存された順序に従ってメニューを再構築
        foreach ( $saved_settings as $setting ) {
            $type = isset( $setting['type'] ) ? $setting['type'] : 'menu';
            $slug = $setting['slug'];

            // グループ（開閉親）の場合
            if ( $type === 'group' ) {
                $current_group_id = $slug;
                $group_title = ! empty( $setting['title'] ) ? $setting['title'] : 'グループ';
                
                // ダミーのトップレベルメニューとしてグループ親を挿入
                $new_menu[ $position ] = array(
                    $group_title,
                    'read',
                    $slug,
                    $group_title,
                    'menu-top trsmf-group-header',
                    $slug,
                    'dashicons-category'
                );
                
                // JSで識別するためのデータ属性用クラスを付与
                add_filter( 'add_menu_classes', function( $menu_classes ) use ( $slug ) {
                    foreach ( $menu_classes as &$m ) {
                        if ( $m[2] === $slug ) {
                            $m[4] .= ' trsmf-group-id-' . esc_attr( $slug );
                        }
                    }
                    return $menu_classes;
                });

                $position += 5;
                continue;
            }

            // 通常のメニュー項目の場合
            if ( isset( $menu_by_slug[ $slug ] ) ) {
                $menu_item = $menu_by_slug[ $slug ];
                unset( $menu_by_slug[ $slug ] ); // 処理済みとして削除

                // 1. 非表示設定（ただし「設定」画面自体はロックアウト防止のため非表示にしない）
                if ( ! empty( $setting['hidden'] ) && $slug !== 'options-general.php' ) {
                    continue;
                }

                // 2. グループに所属させる設定
                if ( ! empty( $setting['in_group'] ) && $current_group_id !== null ) {
                    $menu_item[4] .= ' trsmf-grouped-item';
                    // どのグループに属するかJSで紐付けるためのインラインスクリプト出力
                    $target_slug = $slug;
                    $parent_id   = $current_group_id;
                    add_action( 'admin_footer', function() use ( $target_slug, $parent_id ) {
                        echo '<script>
                            jQuery(function($){
                                $("#adminmenu a[href=\'' . esc_js( $target_slug ) . '\'], #adminmenu a[href=\'admin.php?page=' . esc_js( $target_slug ) . '\']").closest("li.menu-top").attr("data-trsmf-parent", "' . esc_js( $parent_id ) . '");
                                $("#adminmenu a[href=\'' . esc_js( $parent_id ) . '\'], #adminmenu a[href=\'admin.php?page=' . esc_js( $parent_id ) . '\']").closest("li.menu-top").attr("data-trsmf-group", "' . esc_js( $parent_id ) . '");
                            });
                        </script>';
                    });
                } else {
                    // グループ所属チェックが外れていたら、以降のメニューのためにグループコンテキストをリセット
                    $current_group_id = null;
                }

                $new_menu[ $position ] = $menu_item;
                $position += 5;
            }
        }

        // 新しく追加されたプラグインなど、設定データにまだ存在しないメニューを末尾に追加
        foreach ( $menu_by_slug as $slug => $menu_item ) {
            $new_menu[ $position ] = $menu_item;
            $position += 5;
        }

        $menu = $new_menu;
    }

    /**
     * 設定の保存処理
     */
    public function save_settings() {
        if ( ! isset( $_POST['trsmf_save_nonce'] ) || ! wp_verify_nonce( $_POST['trsmf_save_nonce'], 'trsmf_save_action' ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( isset( $_POST['trsmf_reset'] ) ) {
            delete_option( $this->option_name );
            add_settings_error( 'trsmf_messages', 'trsmf_message', 'メニュー設定を初期状態にリセットしました。', 'updated' );
            return;
        }

        if ( isset( $_POST['trsmf_settings_data'] ) ) {
            $raw_json = stripslashes( $_POST['trsmf_settings_data'] );
            $decoded  = json_decode( $raw_json, true );

            if ( is_array( $decoded ) ) {
                $sanitized = array();
                foreach ( $decoded as $item ) {
                    $sanitized[] = array(
                        'type'     => sanitize_text_field( $item['type'] ),
                        'slug'     => sanitize_text_field( $item['slug'] ),
                        'title'    => isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '',
                        'hidden'   => ! empty( $item['hidden'] ),
                        'in_group' => ! empty( $item['in_group'] ),
                    );
                }
                update_option( $this->option_name, $sanitized );
                add_settings_error( 'trsmf_messages', 'trsmf_message', 'メニュー設定を保存しました。', 'updated' );
            }
        }
    }

    /**
     * 設定画面のHTMLレンダリング
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $saved_settings = get_option( $this->option_name, array() );
        
        // 元のメニュー情報をスラッグで引けるように整理
        $available_menus = array();
        foreach ( $this->original_menu as $item ) {
            $slug = $item[2];
            // セパレーター（区切り線）の処理
            if ( strpos( $slug, 'separator' ) !== false ) {
                $title = '--- 区切り線 ---';
            } else {
                // 通知バッジ（数字）などのHTMLタグを除去してタイトルを取得
                $title = trim( preg_replace( '/<span[^>]*>.*?<\/span>/i', '', $item[0] ) );
                $title = wp_strip_all_tags( $title );
            }
            $available_menus[ $slug ] = array(
                'title' => $title ? $title : $slug,
                'slug'  => $slug,
            );
        }

        // 表示用リストの構築（保存済み設定をベースに、未登録メニューを後ろに追加）
        $display_list = array();
        $used_slugs   = array();

        if ( ! empty( $saved_settings ) ) {
            foreach ( $saved_settings as $s ) {
                if ( $s['type'] === 'group' ) {
                    $display_list[] = $s;
                } elseif ( isset( $available_menus[ $s['slug'] ] ) ) {
                    $s['title'] = $available_menus[ $s['slug'] ]['title'];
                    $display_list[] = $s;
                    $used_slugs[] = $s['slug'];
                }
            }
        }

        foreach ( $available_menus as $slug => $m ) {
            if ( ! in_array( $slug, $used_slugs, true ) ) {
                $display_list[] = array(
                    'type'     => 'menu',
                    'slug'     => $slug,
                    'title'    => $m['title'],
                    'hidden'   => false,
                    'in_group' => false,
                );
            }
        }
        ?>
        <div class="wrap">
            <h1>TRS Menu Fold</h1>
            <?php settings_errors( 'trsmf_messages' ); ?>

            <div class="trsmf-group-creator">
                <h3>1. 開閉グループ（親メニュー）の追加</h3>
                <p class="description">複数のメニューをまとめて開閉式にするための「親見出し」を作成します。作成後、下のリストで親見出しの<strong>すぐ下</strong>にまとめたいメニューを配置し、「直上のグループに格納」にチェックを入れてください。</p>
                <div style="display:flex; gap:10px; margin-top:10px;">
                    <input type="text" id="trsmf-new-group-name" placeholder="例：コンテンツ管理、各種設定など" class="regular-text">
                    <button type="button" id="trsmf-add-group-btn" class="button button-secondary">開閉グループを追加</button>
                </div>
            </div>

            <form method="post" id="trsmf-settings-form">
                <?php wp_nonce_field( 'trsmf_save_action', 'trsmf_save_nonce' ); ?>
                <input type="hidden" name="trsmf_settings_data" id="trsmf-settings-data" value="">

                <h3 style="margin-top:25px;">2. メニューの並び替え・非表示・グループ格納設定</h3>
                <p class="description">ドラッグ＆ドロップで順番を変更できます。（※「設定」メニューはロックアウト防止のため非表示にできません）</p>

                <ul id="trsmf-menu-list">
                    <?php foreach ( $display_list as $item ) : ?>
                        <?php if ( $item['type'] === 'group' ) : ?>
                            <li class="trsmf-menu-item trsmf-is-group" data-slug="<?php echo esc_attr( $item['slug'] ); ?>" data-type="group">
                                <div class="trsmf-item-left">
                                    <span class="dashicons dashicons-menu trsmf-drag-handle"></span>
                                    <span class="dashicons dashicons-category" style="color:#2271b1;"></span>
                                    <input type="text" class="trsmf-group-title-input" value="<?php echo esc_attr( $item['title'] ); ?>" style="font-weight:bold;">
                                    <span class="trsmf-item-slug">(開閉グループ親)</span>
                                </div>
                                <div class="trsmf-item-controls">
                                    <button type="button" class="button trsmf-remove-group-btn" style="color:#d63638;">削除</button>
                                </div>
                            </li>
                        <?php else : ?>
                            <?php 
                                $is_hidden = ! empty( $item['hidden'] );
                                $in_group  = ! empty( $item['in_group'] );
                                $is_settings = ( $item['slug'] === 'options-general.php' );
                            ?>
                            <li class="trsmf-menu-item <?php echo $is_hidden ? 'trsmf-is-hidden' : ''; ?>" data-slug="<?php echo esc_attr( $item['slug'] ); ?>" data-type="menu">
                                <div class="trsmf-item-left">
                                    <span class="dashicons dashicons-menu trsmf-drag-handle"></span>
                                    <span class="trsmf-item-title"><?php echo esc_html( $item['title'] ); ?></span>
                                    <span class="trsmf-item-slug">(<?php echo esc_html( $item['slug'] ); ?>)</span>
                                </div>
                                <div class="trsmf-item-controls">
                                    <label>
                                        <input type="checkbox" class="trsmf-ingroup-checkbox" <?php checked( $in_group ); ?>>
                                        直上のグループに格納
                                    </label>
                                    <label style="<?php echo $is_settings ? 'opacity:0.4;' : ''; ?>">
                                        <input type="checkbox" class="trsmf-hide-checkbox" <?php checked( $is_hidden ); ?> <?php disabled( $is_settings ); ?>>
                                        非表示にする
                                    </label>
                                </div>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>

                <p class="submit" style="display:flex; gap:15px;">
                    <input type="submit" name="trsmf_save" class="button button-primary" value="変更を保存">
                    <input type="submit" name="trsmf_reset" class="button button-secondary" value="初期状態にリセット" onclick="return confirm('すべてのカスタマイズをリセットして元に戻しますか？');">
                </p>
            </form>
        </div>
        <?php
    }
}

new TRS_Menu_Fold();