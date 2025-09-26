<?php
require_once("../include/bittorrent.php");
dbconn();
loggedinorreturn();

// 确保PHP内部字符串处理使用UTF-8编码
mb_internal_encoding("UTF-8");
// 显式设置HTTP响应头，确保浏览器以UTF-8解析页面
header('Content-Type: text/html; charset=UTF-8');

$user_id = $CURUSER['id'];
$user = $CURUSER;

// 记录页面加载时的魔力值，作为“本局开始前魔力值”的初始值
// 在每次AJAX请求开始时，这个值也会被重新设置为当前用户的魔力值
// 实际的“本局开始前魔力值”会存储在 $game_state 中
$magic_on_initial_load = $user['seedbonus'];

$error = '';
$message = '';
$game_state = []; // 用于存储游戏状态，如玩家手牌、庄家手牌、赌注等

// 定义下注选项
$bet_options = [1000, 2000, 5000]; // 根据图片调整为 1000, 2000, 5000

// 卡牌值映射
function get_card_value($card) {
    // 使用 mb_substr 确保正确处理多字节字符（如花色符号）
    $rank = mb_substr($card, 0, -1, 'UTF-8'); // 获取牌面，如 'A', 'K', '10'
    if (in_array($rank, ['J', 'Q', 'K'])) {
        return 10;
    } elseif ($rank === 'A') {
        return 11; // A初始算11，后续根据手牌总点数调整
    } else {
        return (int)$rank;
    }
}

// 计算手牌点数，处理A的特殊情况
function calculate_hand_value($hand) {
    $value = 0;
    $aces = 0;
    foreach ($hand as $card) {
        $card_value = get_card_value($card);
        if ($card_value === 11) {
            $aces++;
        }
        $value += $card_value;
    }

    // 如果点数超过21且有A，将A的值从11改为1
    while ($value > 21 && $aces > 0) {
        $value -= 10; // Convert one Ace from 11 to 1
        $aces--;
    }
    return $value;
}

// 发一张牌
function deal_card() {
    $suits = ['♠', '♥', '♦', '♣']; // 黑桃、红心、方块、梅花
    $ranks = ['A', '2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K'];
    $random_suit = $suits[array_rand($suits)];
    $random_rank = $ranks[array_rand($ranks)];
    return $random_rank . $random_suit;
}

// 记录魔力值变动日志
function log_magic_change($user_id, $initial_magic, $change_amount, $new_magic, $description) {
    // 确保 BonusLogs 类存在且 add 方法可用
    if (class_exists('\App\Models\BonusLogs') && method_exists('\App\Models\BonusLogs', 'add')) {
        // 假设 BUSINESS_TYPE_21dot 存在于 \App\Models\BonusLogs 类中
        \App\Models\BonusLogs::add($user_id, $initial_magic, abs($change_amount), $new_magic, $description, \App\Models\BonusLogs::BUSINESS_TYPE_21dot);
    } else {
        // 如果 BonusLogs 不可用，可以记录到其他地方或忽略
        // error_log("BonusLogs class or method not found. Magic change not logged for user $user_id: $description");
    }
}

// 处理游戏请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 从隐藏字段获取游戏状态
    if (isset($_POST['game_state_json'])) {
        $game_state = json_decode($_POST['game_state_json'], true);
    }

    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'start_game':
            $bet_amount = (int)($_POST['bet_amount'] ?? 0);

            // !!! 关键：在扣除赌注前，保存当前魔力值作为“本局开始前魔力值” !!!
            $game_state['magic_before_bet'] = $user['seedbonus']; 

            if (!in_array($bet_amount, $bet_options)) {
                $error = "无效的下注金额。";
                $cheat_deduction = 1000000;
                sql_query("UPDATE users SET seedbonus = seedbonus - " . sqlesc($cheat_deduction) . " WHERE id = " . sqlesc($user_id));
                $user['seedbonus'] -= $cheat_deduction;
                log_magic_change($user_id, $game_state['magic_before_bet'], -$cheat_deduction, $user['seedbonus'], "21点作弊扣除魔力: " . $cheat_deduction . "魔力");
                break;
            }

            if ($user['seedbonus'] < $bet_amount) {
                $error = "魔力值不足，无法下注。";
                break;
            }



            // 扣除赌注
            sql_query("UPDATE users SET seedbonus = seedbonus - " . sqlesc($bet_amount) . " WHERE id = " . sqlesc($user_id));
            $user['seedbonus'] -= $bet_amount; // 更新当前用户的魔力值
            log_magic_change($user_id, $game_state['magic_before_bet'], -$bet_amount, $user['seedbonus'], "21点下注: " . $bet_amount . "魔力");

            // 发牌
            $player_hand = [deal_card(), deal_card()];
            $dealer_hand = [deal_card(), deal_card()]; // 庄家一张牌隐藏

            // 强力防御性检查：确保庄家手牌在开始游戏时只有两张
            if (count($dealer_hand) > 2) {
                $dealer_hand = array_slice($dealer_hand, 0, 2);
            }

            $player_value = calculate_hand_value($player_hand);
            $dealer_value = calculate_hand_value($dealer_hand);

            $game_state = array_merge($game_state, [ // 合并而不是覆盖，保留 magic_before_bet
                'bet_amount' => $bet_amount,
                'player_hand' => $player_hand,
                'dealer_hand' => $dealer_hand,
                'status' => 'playing', // playing, player_bust, dealer_bust, player_21dot, dealer_21dot, player_win, dealer_win, push
                'player_21dot' => false,
                'dealer_21dot' => false,
            ]);

            // 检查初始21点
            if ($player_value === 21 && count($player_hand) === 2) {
                $game_state['player_21dot'] = true;
            }
            if ($dealer_value === 21 && count($dealer_hand) === 2) { // 只有两张牌的21点才算21点
                $game_state['dealer_21dot'] = true;
            }

            if ($game_state['player_21dot'] && $game_state['dealer_21dot']) {
                $game_state['status'] = 'push';
                $message = "双方都是21点！平局，赌注已退还。";
                // 退还赌注
                sql_query("UPDATE users SET seedbonus = seedbonus + " . sqlesc($bet_amount) . " WHERE id = " . sqlesc($user_id));
                $user['seedbonus'] += $bet_amount;
                log_magic_change($user_id, $game_state['magic_before_bet'], $bet_amount, $user['seedbonus'], "21点平局退还赌注: " . $bet_amount . "魔力");
            } elseif ($game_state['player_21dot']) {
                $game_state['status'] = 'player_win';
                $win_amount = $bet_amount * 2.5; // 21点 1.5倍赔率 (本金+1.5倍盈利)
                sql_query("UPDATE users SET seedbonus = seedbonus + " . sqlesc($win_amount) . " WHERE id = " . sqlesc($user_id));
                $user['seedbonus'] += $win_amount;
                $message = "21点！你赢了 " . number_format($win_amount) . " 魔力！";
                log_magic_change($user_id, $game_state['magic_before_bet'], $win_amount, $user['seedbonus'], "21点获胜: " . $win_amount . "魔力");
            } elseif ($game_state['dealer_21dot']) { // 只有庄家是两张牌的21点才立即结束
                $game_state['status'] = 'dealer_win';
                $message = "庄家21点！你输了 " . number_format($bet_amount) . " 魔力。";
                // 赌注已扣除，无需额外操作
                log_magic_change($user_id, $game_state['magic_before_bet'], 0, $user['seedbonus'], "21点庄家获胜，玩家输掉: " . $bet_amount . "魔力");
            }
            // 如果以上条件都不满足，游戏状态保持 'playing'，玩家可以要牌或停牌
            break;

        case 'hit':
            if ($game_state['status'] !== 'playing') {
                $error = "游戏已结束，无法要牌。";
                break;
            }
            $game_state['player_hand'][] = deal_card();
            $player_value = calculate_hand_value($game_state['player_hand']);

            if ($player_value > 21) {
                $game_state['status'] = 'player_bust';
                $message = "你爆牌了！你输了 " . number_format($game_state['bet_amount']) . " 魔力。";
                log_magic_change($user_id, $game_state['magic_before_bet'], 0, $user['seedbonus'], "21点玩家爆牌，输掉: " . $game_state['bet_amount'] . "魔力");
            }
            break;

        case 'stand':
            if ($game_state['status'] !== 'playing') {
                $error = "游戏已结束，无法停牌。";
                break;
            }

            // 庄家回合
            $dealer_value = calculate_hand_value($game_state['dealer_hand']);
            while ($dealer_value < 17) { // 庄家必须在17点或以上停牌
                $game_state['dealer_hand'][] = deal_card();
                $dealer_value = calculate_hand_value($game_state['dealer_hand']);
            }

            $player_value = calculate_hand_value($game_state['player_hand']);

            if ($dealer_value > 21) {
                $game_state['status'] = 'dealer_bust';
                $win_amount = $game_state['bet_amount'] * 2; // 普通赢牌1倍赔率 (本金+1倍盈利)
                sql_query("UPDATE users SET seedbonus = seedbonus + " . sqlesc($win_amount) . " WHERE id = " . sqlesc($user_id));
                $user['seedbonus'] += $win_amount;
                $message = "庄家爆牌！你赢了 " . number_format($win_amount) . " 魔力！";
                log_magic_change($user_id, $game_state['magic_before_bet'], $win_amount, $user['seedbonus'], "21点庄家爆牌，玩家获胜: " . $win_amount . "魔力");
            } elseif ($player_value > $dealer_value) {
                $game_state['status'] = 'player_win';
                $win_amount = $game_state['bet_amount'] * 2; // 普通赢牌1倍赔率 (本金+1倍盈利)
                sql_query("UPDATE users SET seedbonus = seedbonus + " . sqlesc($win_amount) . " WHERE id = " . sqlesc($user_id));
                $user['seedbonus'] += $win_amount;
                $message = "你赢了！你赢了 " . number_format($win_amount) . " 魔力！";
                log_magic_change($user_id, $game_state['magic_before_bet'], $win_amount, $user['seedbonus'], "21点玩家获胜: " . $win_amount . "魔力");
            } elseif ($dealer_value > $player_value) {
                $game_state['status'] = 'dealer_win';
                $message = "庄家赢了！你输了 " . number_format($game_state['bet_amount']) . " 魔力。";
                log_magic_change($user_id, $game_state['magic_before_bet'], 0, $user['seedbonus'], "21点庄家获胜，玩家输掉: " . $game_state['bet_amount'] . "魔力");
            } else {
                $game_state['status'] = 'push';
                $win_amount = $game_state['bet_amount']; // 平局退还赌注
                sql_query("UPDATE users SET seedbonus = seedbonus + " . sqlesc($win_amount) . " WHERE id = " . sqlesc($user_id));
                $user['seedbonus'] += $win_amount;
                $message = "平局！赌注已退还。";
                log_magic_change($user_id, $game_state['magic_before_bet'], $win_amount, $user['seedbonus'], "21点平局退还赌注: " . $win_amount . "魔力");
            }
            break;
    }

    // 重新获取用户最新魔力值，以防其他地方有变动
    $user_query = sql_query("SELECT seedbonus FROM users WHERE id = " . sqlesc($user_id));
    $user_updated = mysqli_fetch_assoc($user_query);
    $user['seedbonus'] = $user_updated['seedbonus'];
}

// 格式化字节数 (从原代码复制，虽然这里用不到)
function format_bytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// --- 核心：动态游戏内容渲染函数 ---
// 这个函数将生成 #game-content 内部的所有 HTML
function render_dynamic_game_content($game_state, $error, $message, $bet_options, $user) {
    ob_start(); // 开始捕获输出

    // 确定“本局开始前魔力值”的显示值
    // 如果游戏正在进行或已结束，使用 game_state 中保存的值
    // 否则（游戏未开始），使用当前魔力值
    $magic_before_round_display = $game_state['magic_before_bet'] ?? $user['seedbonus'];

    // !!! 关键：嵌入隐藏的输入框，供 JavaScript 读取并更新外部魔力值显示 !!!
    echo '<input type="hidden" id="js-current-magic-value" value="' . number_format($user['seedbonus']) . '">';
    echo '<input type="hidden" id="js-magic-before-round-value" value="' . number_format($magic_before_round_display) . '">';
    ?>
    <?php
    // 检查游戏是否正在进行中
    $is_game_playing = !empty($game_state) && $game_state['status'] === 'playing';
    $is_game_ended = !empty($game_state) && $game_state['status'] !== 'playing';
    ?>

    <?php if ($error): ?>
        <p class="error"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>

    <?php if (!$is_game_playing && !$is_game_ended): // 游戏未开始，显示下注选项 ?>
        <h2>选择你的赌注：</h2>
        <form id="game-form" method="POST"> <!-- 统一的表单ID -->
            <div class="bet-options">
                <?php foreach ($bet_options as $bet): ?>
                    <button type="submit" name="start_game" value="<?php echo $bet; ?>"
                        <?php echo ($user['seedbonus'] < $bet) ? 'disabled' : ''; ?>>
                        下注 <?php echo number_format($bet); ?> 魔力
                    </button>
                <?php endforeach; ?>
            </div>
        </form>
    <?php elseif ($is_game_playing): // 游戏进行中，显示手牌和操作按钮 ?>
        <h2>游戏进行中</h2>
        <p>你的赌注：<?php echo number_format($game_state['bet_amount']); ?> 魔力</p>

        <h3>庄家的牌: (<?php echo ($game_state['status'] === 'playing') ? '?' : calculate_hand_value($game_state['dealer_hand']); ?>点)</h3>
        <div class="hand">
            <?php foreach ($game_state['dealer_hand'] as $index => $card): ?>
                <?php if ($index === 1 && $game_state['status'] === 'playing'): ?>
                    <div class="card hidden">背面</div>
                <?php else: ?>
                    <div class="card"><?php echo $card; ?></div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <h3>你的牌: (<?php echo calculate_hand_value($game_state['player_hand']); ?>点)</h3>
        <div class="hand">
            <?php foreach ($game_state['player_hand'] as $card): ?>
                <div class="card"><?php echo $card; ?></div>
            <?php endforeach; ?>
        </div>

        <form id="game-form" method="POST"> <!-- 统一的表单ID -->
            <input type="hidden" name="game_state_json" value="<?php echo htmlspecialchars(json_encode($game_state)); ?>">
            <div class="game-actions">
                <button type="submit" name="hit" <?php echo ($game_state['status'] !== 'playing') ? 'disabled' : ''; ?>>要牌 (Hit)</button>
                <button type="submit" name="stand" <?php echo ($game_state['status'] !== 'playing') ? 'disabled' : ''; ?>>停牌 (Stand)</button>
            </div>
        </form>
    <?php elseif ($is_game_ended): // 游戏已结束，显示最终结果和重新开始按钮 ?>
        <h2>游戏结束！</h2>
        <?php if ($message): ?>
            <p class="message"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>
        <p>你的赌注：<?php echo number_format($game_state['bet_amount']); ?> 魔力</p>

        <h3>庄家的牌: (<?php echo calculate_hand_value($game_state['dealer_hand']); ?>点)</h3>
        <div class="hand">
            <?php foreach ($game_state['dealer_hand'] as $card): ?>
                <div class="card"><?php echo $card; ?></div>
            <?php endforeach; ?>
        </div>

        <h3>你的牌: (<?php echo calculate_hand_value($game_state['player_hand']); ?>点)</h3>
        <div class="hand">
            <?php foreach ($game_state['player_hand'] as $card): ?>
                <div class="card"><?php echo $card; ?></div>
            <?php endforeach; ?>
        </div>

        <h2 style="margin-top: 30px;">再来一局？</h2>
        <form id="game-form" method="POST"> <!-- 统一的表单ID -->
            <div class="bet-options">
                <?php foreach ($bet_options as $bet): ?>
                    <button type="submit" name="start_game" value="<?php echo $bet; ?>"
                        <?php echo ($user['seedbonus'] < $bet) ? 'disabled' : ''; ?>>
                        下注 <?php echo number_format($bet); ?> 魔力
                    </button>
                <?php endforeach; ?>
            </div>
        </form>
    <?php endif; ?>
    <?php
    return ob_get_clean(); // 返回捕获的HTML
}

// --- 判断是否为 AJAX 请求并分流输出 ---
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($is_ajax) {
    // 如果是 AJAX 请求，只输出动态游戏内容
    echo render_dynamic_game_content($game_state, $error, $message, $bet_options, $user);
    exit; // 终止脚本，不输出完整HTML
}

// --- 首次页面加载时输出完整 HTML 结构 ---
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>21点</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            background-color: #2c3e50; /* 深蓝色背景 */
            color: #ecf0f1; /* 浅色文字 */
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 100vh;
        }
        h1, h2 {
            color: #f1c40f; /* 黄色标题 */
            text-align: center;
            margin-bottom: 20px;
        }
        p {
            color: #bdc3c7;
            text-align: center;
        }
        .container {
            background-color: #34495e; /* 稍浅的蓝色背景 */
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 600px;
            margin-bottom: 20px;
        }
        .current-status {
            background-color: #2980b9; /* 蓝色 */
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }
        .bet-options button,
        .game-actions button {
            background-color: #27ae60; /* 绿色 */
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            margin: 5px;
            transition: background-color 0.3s ease;
        }
        .bet-options button:hover,
        .game-actions button:hover {
            background-color: #2ecc71;
        }
        .bet-options button:disabled,
        .game-actions button:disabled {
            background-color: #7f8c8d; /* 灰色 */
            cursor: not-allowed;
        }
        .game-area {
            margin-top: 20px;
            border-top: 1px solid #4a627a;
            padding-top: 20px;
        }
        .hand {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            margin-bottom: 15px;
        }
        .card {
            background-color: #fdfdfd; /* 白色卡牌背景 */
            color: #333;
            border: 1px solid #ccc;
            border-radius: 5px;
            padding: 10px 15px;
            margin: 5px;
            font-size: 20px;
            font-weight: bold;
            box-shadow: 2px 2px 5px rgba(0, 0, 0, 0.2);
            min-width: 60px;
            text-align: center;
        }
        .card.hidden {
            background-color: #c0392b; /* 红色背面 */
            color: white;
            font-size: 18px;
            padding: 10px 15px;
        }
        .error {
            color: #e74c3c; /* 红色错误信息 */
            font-weight: bold;
            text-align: center;
            margin-top: 15px;
        }
        .message {
            color: #3498db; /* 蓝色消息 */
            font-weight: bold;
            text-align: center;
            margin-top: 15px;
            font-size: 1.1em;
        }
        .spin-effect {
            animation: spin3d 0.5s forwards; /* 动画只播放一次 */
        }

        @keyframes spin3d {
            0% {
                transform: rotateY(0deg);
            }
            100% {
                transform: rotateY(360deg);
            }
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const gameContentDiv = document.getElementById('game-content');
            const currentMagicSpan = document.getElementById('current-magic');
            const magicBeforeRoundSpan = document.getElementById('magic-before-round'); // 获取“本局开始前魔力值”的span

            // 模拟一个简单的动画效果，然后更新内容
            function updateGameContent(html) {
                gameContentDiv.classList.add('spin-effect');
                setTimeout(() => {
                    gameContentDiv.innerHTML = html;
                    gameContentDiv.classList.remove('spin-effect');
                    // ！！！重要：已移除所有自动滚动代码。
                    // 任何自动滚动行为都已禁用。
                    // 如果你仍然遇到画面“跑掉”的问题，请尝试强制刷新浏览器缓存 (Ctrl+F5 或 Cmd+Shift + R)。

                    // 重新绑定事件监听器，因为innerHTML会替换DOM
                    bindFormSubmitEvent();

                    // !!! 关键：更新外部魔力值显示 !!!
                    const newCurrentMagicInput = document.getElementById('js-current-magic-value');
                    const newMagicBeforeRoundInput = document.getElementById('js-magic-before-round-value');

                    if (newCurrentMagicInput && currentMagicSpan) {
                        currentMagicSpan.textContent = newCurrentMagicInput.value;
                    }
                    if (newMagicBeforeRoundInput && magicBeforeRoundSpan) {
                        magicBeforeRoundSpan.textContent = newMagicBeforeRoundInput.value;
                    }

                }, 500); // 动画持续时间
            }

            async function handleSubmit(e) {
                e.preventDefault(); // 阻止默认表单提交

                const formData = new FormData(this);
                const clickedButton = e.submitter;
                formData.append('action', clickedButton.name); // 添加action参数

                // 如果是开始游戏，添加下注金额
                if (clickedButton.name === 'start_game') {
                    formData.append('bet_amount', clickedButton.value);
                }

                try {
                    const response = await fetch('21dot.php', {
                        method: 'POST',
                        // 关键：添加 X-Requested-With 头，告诉 PHP 这是 AJAX 请求
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: formData
                    });
                    const html = await response.text();
                    updateGameContent(html);

                } catch (error) {
                    console.error('Error:', error);
                    alert('游戏过程中发生错误，请重试。');
                    gameContentDiv.classList.remove('spin-effect');
                }
            }

            function bindFormSubmitEvent() {
                // 统一的表单ID
                const gameForm = document.getElementById('game-form');
                if (gameForm) {
                    gameForm.removeEventListener('submit', handleSubmit); // 避免重复绑定
                    gameForm.addEventListener('submit', handleSubmit);
                }
            }

            // 初始绑定事件监听器
            bindFormSubmitEvent();
        });
    </script>
</head>
<body>
    <!-- 静态标题和说明，只渲染一次 -->
    <h1>21点</h1>
    <p>欢迎来到21点！选择你的赌注，尝试击败庄家！</p>
    <p>玩家21点赔率1.5倍，普通赢牌赔率1倍，平局退还赌注。</p>

    <div class="container">
        <div class="current-status">
            <p>当前魔力值：<span id="current-magic"><?php echo number_format($user['seedbonus']); ?></span></p>
            <!-- 初始显示时，本局开始前魔力值就是当前魔力值 -->
            <p>本局开始前魔力值：<span id="magic-before-round"><?php echo number_format($user['seedbonus']); ?></span></p>
        </div>

        <!-- 动态游戏内容区域，AJAX 会更新这里 -->
        <div id="game-content">
            <?php
            // 首次加载时，调用函数渲染动态内容
            echo render_dynamic_game_content($game_state, $error, $message, $bet_options, $user);
            ?>
        </div>
    </div>
</body>
</html>
