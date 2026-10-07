<?php

// Create the starting values, boxes, enemies, and pits for a level.
function fresh($level = 1, $score = 0, $lives = 3)
{
    $blocks = [];
    $blockPositions = [[380, 340], [620, 285], [900, 340], [950, 340], [1500, 300]];

    // Build the list of question boxes from their positions.
    foreach ($blockPositions as $position) { $blocks[] = ['x' => $position[0], 'y' => $position[1], 'used' => false]; }
    $enemies = [];

    // Create enemies with a starting direction and an alive flag.
    foreach ([550, 1100, 1750] as $x) { $enemies[] = ['x' => $x, 'y' => 422, 'v' => -1, 'alive' => true]; }
    $pits = $level === 1
        ? [[1250, 1350]]
        : ($level === 2 ? [[760, 860], [1300, 1420]] : [[700, 810], [1200, 1320], [1850, 1960]]);
    return [
        'level' => $level,
        'score' => $score,
        'lives' => $lives,
        'time' => 400,
        'tick' => 0,
        'x' => 80,
        'y' => 410,
        'vx' => 0,
        'vy' => 0,
        'ground' => false,
        'coins' => 0,
        'coinPops' => [],
        'status' => 'playing',
        'inv' => 0,
        'blocks' => $blocks,
        'enemies' => $enemies,
        'pits' => $pits,
    ];
}

// Compare rectangle edges to detect a collision.
function overlap($first, $second)
{
    return $first['x'] < $second['x'] + $second['w']
        && $first['x'] + $first['w'] > $second['x']
        && $first['y'] < $second['y'] + $second['h']
        && $first['y'] + $first['h'] > $second['y'];
}

// Remove one life. Respawn if lives remain; otherwise end the game.
function hurt($gameState)
{
    $gameState['lives']--;
    if (!$gameState['lives']) { $gameState['status'] = 'lost'; return $gameState; }
    $gameState['x'] = 80;
    $gameState['y'] = 410;
    // Set horizontal speed. Jump only when standing; then apply gravity.
    $gameState['vx'] = 0;
    $gameState['vy'] = 0;
    $gameState['inv'] = 60;
    return $gameState;
}

// Advance the game by one update using the player input.
function step($gameState, $input)
{
    if ($gameState['status'] !== 'playing') { return $gameState; }
    
    // Update the timer, coin animations, and damage-protection counter.
    $gameState['tick']++;
    $gameState['coinPops'] = array_values(array_filter(
        $gameState['coinPops'],
        function ($coin) use ($gameState) { return $gameState['tick'] - $coin['tick'] < 24; }
    ));
    $gameState['time'] = max(0, 400 - floor($gameState['tick'] / 30));
    $gameState['inv'] = max(0, $gameState['inv'] - 1);
    $previousY = $gameState['y'];
    $gameState['vx'] = (!empty($input['right']) ? 5 : 0)
        - (!empty($input['left']) ? 5 : 0);
    if (!empty($input['jump']) && $gameState['ground']) { $gameState['vy'] = -13; $gameState['ground'] = false; }
    $gameState['vy'] = min(14, $gameState['vy'] + .65);
    $gameState['x'] = max(0, min(2370, $gameState['x'] + $gameState['vx']));
    $gameState['y'] += $gameState['vy'];
    $gameState['ground'] = false;

    // & makes each block refer to the actual entry so changes are saved.
    foreach ($gameState['blocks'] as &$block) {
        $playerBounds = ['x' => $gameState['x'], 'y' => $gameState['y'], 'w' => 26, 'h' => 38];
        $blockBounds = ['x' => $block['x'], 'y' => $block['y'], 'w' => 40, 'h' => 40];
        if (!overlap($playerBounds, $blockBounds)) { continue; }
        if ($gameState['vy'] < 0 && $previousY >= $block['y'] + 40) {
            $gameState['y'] = $block['y'] + 40;
            $gameState['vy'] = 0;
           
            // Reward a hit from below once, and create the coin animation.
            if (!$block['used']) {
                $block['used'] = true;
                $gameState['coinPops'][] = [
                    'x' => $block['x'] + 10,
                    'y' => $block['y'] - 28,
                    'tick' => $gameState['tick'],
                ];
                $gameState['coins']++;
                $gameState['score'] += 100;
            }
        } elseif ($gameState['vy'] >= 0 && $previousY + 38 <= $block['y']) {
            $gameState['y'] = $block['y'] - 38;
            $gameState['vy'] = 0;
            $gameState['ground'] = true;
        } else { $gameState['x'] -= $gameState['vx']; }
    }
    
    // Remove the loop reference so later variables cannot change this box.
    unset($block);
    
    // Check every pit before allowing the player to stand on the floor.
    $overPit = false;
    foreach ($gameState['pits'] as $pit) {
        if ($gameState['x'] + 13 > $pit[0] && $gameState['x'] + 13 < $pit[1]) { $overPit = true; }
    }
    if (!$overPit && $gameState['y'] + 38 >= 460 && $previousY + 38 <= 474) { $gameState['y'] = 422; $gameState['vy'] = 0; $gameState['ground'] = true; }
    
    // Loop through enemies: move, turn at edges, and check player contact.
    foreach ($gameState['enemies'] as &$enemy) {
        if (!$enemy['alive']) { continue; }
        $enemy['x'] += $enemy['v'] * (1 + $gameState['level'] * .15);
        $atEdge = $enemy['x'] < 100 || $enemy['x'] > 2250;
        foreach ($gameState['pits'] as $pit) {
            if ($enemy['x'] + 28 > $pit[0] && $enemy['x'] < $pit[1]) { $atEdge = true; }
        }
        if ($atEdge) { $enemy['v'] = -$enemy['v']; $enemy['x'] += $enemy['v'] * 35; }
        $playerBounds = ['x' => $gameState['x'], 'y' => $gameState['y'], 'w' => 26, 'h' => 38];
        $enemyBounds = ['x' => $enemy['x'], 'y' => $enemy['y'], 'w' => 30, 'h' => 38];
        if (overlap($playerBounds, $enemyBounds)) {
            if ($gameState['vy'] > 0 && $previousY + 38 <= $enemy['y'] + 12) { $enemy['alive'] = false; $gameState['vy'] = -8; $gameState['score'] += 200; } elseif (!$gameState['inv']) { unset($enemy); return hurt($gameState); }
        }
    }
    unset($enemy);

    // Falling out of the level or reaching zero time costs a life.
    if ($gameState['y'] > 600 || $gameState['time'] == 0) { return hurt($gameState); }
    
    // Finish the level and add a time bonus; level 3 ends in victory.
    if ($gameState['x'] > 2280) {
        if ($gameState['level'] === 3) { $gameState['status'] = 'won'; $gameState['score'] += $gameState['time'] * 10; } else {
            return fresh(
                $gameState['level'] + 1,
                $gameState['score'] + $gameState['time'] * 10,
                $gameState['lives']
            );
        }
    }
    return $gameState;
}

// POST requests update the game; normal page requests display the HTML below.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Store one game state per player session between browser requests.
    session_start();
    header('Content-Type: application/json');
   
    // Read JSON input. ?? supplies defaults for missing values.
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
   
    // Either reset the session game or advance it using the supplied keys.
    if (($input['action'] ?? '') === 'reset' || !isset($_SESSION['singleFileGame'])) {
        $level = max(1, min(3, (int) ($input['level'] ?? 1)));
        $_SESSION['singleFileGame'] = fresh($level);
    } else {
        $_SESSION['singleFileGame'] = step($_SESSION['singleFileGame'], $input['keys'] ?? []);
    }
    
    // Send only JSON and exit before PHP outputs the HTML page.
    echo json_encode($_SESSION['singleFileGame']);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Platform Quest — PHP</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <!-- Game canvas, action buttons, level selection, and touch controls. -->
  <main>
    <h1>Super Mario Low Budget <small>PHP edition</small></h1>
    <canvas width="960" height="540"></canvas>
    <nav>
      <button id="restart">New game</button>
      <button id="pause">Pause</button>
      <button id="fullscreen">Full screen</button>
      <label>Level <select id="level"><option>1</option><option>2</option><option>3</option></select></label>
    </nav>
    <p>Move: ← → or A/D · Jump: Space, ↑ or W · Pause: P · Hit boxes from below for coins. Reach the flag.</p>
    <div class="touch">
      <button data-key="left">◀</button>
      <button data-key="right">▶</button>
      <button data-key="jump">Jump</button>
    </div>
  </main>
  <script>

// Get the canvas drawing tools and record which controls are pressed.
const canvas = document.querySelector('canvas'),
  context = canvas.getContext('2d'),
  keys = { left: false, right: false, jump: false };
let state = <?php echo json_encode(fresh()); ?>,
  paused = false,
  updateInProgress = false,
  lastUpdateTime = 0;

// Sends the controls to PHP and waits for the updated game.”
async function remote(action = 'step', level = 1) {
  const response = await fetch('index.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action, level, keys })
  });
  if (!response.ok) throw Error('PHP server returned ' + response.status);
  return response.json();
}

// Start a new game or the selected level, then clear pause.
async function reset(level = 1) {
  state = await remote('reset', level);
  paused = false;
  document.querySelector('#pause').textContent = 'Pause';
}

// Convert keyboard keys into left, right, jump, or pause actions.
function key(e, value) {
  let name = {
    ArrowLeft: 'left',
    a: 'left',
    ArrowRight: 'right',
    d: 'right',
    ArrowUp: 'jump',
    w: 'jump',
    ' ': 'jump'
  }[e.key];
  if (name) { e.preventDefault(); keys[name] = value; }
  if (value && e.key === 'p' && !e.repeat) togglePause();
}

// Pressing a key enables its action; releasing it disables the action.
addEventListener('keydown', e => key(e, true));
addEventListener('keyup', e => key(e, false));

// Pause and release all controls when the page loses focus.
addEventListener('blur', () => {
  Object.keys(keys).forEach(k => keys[k] = false);
  paused = true;
  document.querySelector('#pause').textContent = 'Resume';
});

// Loop through the touch buttons and handle press/release events.
document.querySelectorAll('[data-key]').forEach(b => {
  b.onpointerdown = e => { b.setPointerCapture(e.pointerId); keys[b.dataset.key] = true; };
  b.onpointerup = b.onpointercancel = () => keys[b.dataset.key] = false;
});

// Reverse the pause value and update the button label.
function togglePause() {
  paused = !paused;
  document.querySelector('#pause').textContent = paused ? 'Resume' : 'Pause';
}

// Connect the page buttons to their game actions.
document.querySelector('#pause').onclick = togglePause;
document.querySelector('#restart').onclick = () => reset();
document.querySelector('#level').onchange = e => reset(+e.target.value);
document.querySelector('#fullscreen').onclick = () => canvas.requestFullscreen();

// Draw one colored rectangle on the canvas.
function rect(x, y, w, h, color) { context.fillStyle = color; context.fillRect(x, y, w, h); }

// Draw the scrolling world, player, enemies, coins, and score.
function draw() {
 
// Follow the player without scrolling past either end of the level.
  let cameraX = Math.max(0, Math.min(1440, state.x - 300));
  rect(0, 0, 960, 540, '#598ffa');
  context.save();
  context.translate(-cameraX, 0);

  // Repeat cloud and hill shapes across the world.
  for (let x = 120; x < 2400; x += 430) {
    context.fillStyle = '#fff';
    context.beginPath();
    context.ellipse(x, 150, 42, 19, 0, 0, 7);
    context.fill();
    context.fillStyle = '#19ae21';
    context.beginPath();
    context.moveTo(x - 100, 460);
    context.lineTo(x, 360);
    context.lineTo(x + 100, 460);
    context.fill();
  }
 
  // Draw floor blocks, skipping positions that belong to a pit.
  for (let x = 0; x < 2400; x += 40) {
    if (state.pits.some(p => x >= p[0] && x < p[1])) continue;
    rect(x, 460, 39, 80, '#b94c09');
    rect(x, 460, 39, 4, '#ffd1a0');
    rect(x + 1, 480, 37, 2, '#391900');
    rect(x + 18, 482, 2, 18, '#391900');
  }
  
  // Animate coins popping upward from boxes; sine controls their height.
  for (const coin of state.coinPops || []) {
    const age = state.tick - coin.tick,
      cy = coin.y - 45 * Math.sin(age / 24 * Math.PI);
    context.fillStyle = '#ffce24';
    context.strokeStyle = '#a76a00';
    context.lineWidth = 2;
    context.beginPath();
    context.ellipse(coin.x + 10, cy + 14, Math.max(3, 8 * Math.abs(Math.cos(age * .4))), 13, 0, 0, Math.PI * 2);
    context.fill();
    context.stroke();
    rect(coin.x + 9, cy + 6, 3, 16, '#fff3a0');
  }
  
  // Show a question mark for unused boxes and a dot for used boxes.
  for (let b of state.blocks) {
    rect(b.x, b.y, 40, 40, b.used ? '#856647' : '#e9770b');
    context.strokeStyle = '#351600';
    context.strokeRect(b.x, b.y, 40, 40);
    context.fillStyle = '#38210a';
    context.font = 'bold 30px monospace';
    context.fillText(b.used ? '·' : '?', b.x + 10, b.y + 31);
  }
 
  // Draw living enemies; continue skips defeated enemies.
  for (let e of state.enemies) {
    if (!e.alive) continue;
    rect(e.x, e.y + 8, 30, 24, '#a74b13');
    rect(e.x + 5, e.y, 20, 14, '#a74b13');
    rect(e.x + 5, e.y + 14, 5, 7, '#fff');
    rect(e.x + 21, e.y + 14, 5, 7, '#fff');
    rect(e.x - 3, e.y + 32, 14, 6, '#191815');
    rect(e.x + 20, e.y + 32, 14, 6, '#191815');
  }
  
  // Draw the finish flag. Blink the player during temporary protection.
  rect(2310, 200, 5, 260, '#eee');
  rect(2315, 200, 60, 36, '#40c644');
  if (!state.inv || Math.floor(state.inv / 3) % 2) {
    rect(state.x, state.y, 26, 9, '#e64725');
    rect(state.x + 5, state.y + 9, 22, 12, '#ffd194');
    rect(state.x, state.y + 21, 26, 13, '#e64725');
    rect(state.x + 5, state.y + 22, 6, 16, '#293aa7');
    rect(state.x + 17, state.y + 22, 6, 16, '#293aa7');
  }
  
  // Restore screen coordinates so the score does not scroll.
  context.restore();
  context.fillStyle = '#fff';
  context.font = 'bold 22px monospace';
  context.fillText(`SCORE ${state.score}   COINS ${state.coins}   WORLD 1-${state.level}   TIME ${state.time}   LIVES ${state.lives}`, 20, 35);
  // Display a message when paused, victorious, or out of lives.
  if (paused || state.status !== 'playing') {
    rect(220, 200, 520, 120, '#15243ddd');
    context.fillStyle = '#fff';
    context.textAlign = 'center';
    context.fillText(paused ? 'PAUSED' : state.status === 'won' ? 'YOU WIN!' : 'GAME OVER', 480, 255);
    context.font = '16px monospace';
    context.fillText('Use New game to restart', 480, 290);
    context.textAlign = 'left';
  }
}

// Update at a target of 30 steps per second and redraw the screen.
async function frame(t) {
  // Only update when enough time has passed and no update is pending.
  if (t - lastUpdateTime >= 1000 / 30 && !updateInProgress && !paused) {
    lastUpdateTime = t;
    updateInProgress = true;
    // Try the update; catch errors; finally always clears the busy flag.
    try { state = await remote(); } catch (e) {
      paused = true;
      document.querySelector('p').textContent = e.message + ' — run this folder with a PHP server.';
    } finally { updateInProgress = false; }
  }
  draw();
 
  // Schedule the next redraw using the browser animation loop.
  requestAnimationFrame(frame);
}

// Initialize the game, start its animation loop, and display startup errors.
reset().then(() => requestAnimationFrame(frame)).catch(e => document.querySelector('p').textContent = e.message);
  </script>
</body>
</html>
