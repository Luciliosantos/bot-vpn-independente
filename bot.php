<?php
error_reporting(0);
ini_set('display_errors', 0);
set_time_limit(0);

// ==============================================
// CARREGA DADOS DO ARQUIVO OCULTO dadosBot.ini
// ==============================================
if (!file_exists('dadosBot.ini')) {
    echo "❌ ERRO: dadosBot.ini NÃO ENCONTRADO!\n";
    exit;
}

$ini = parse_ini_file('dadosBot.ini', true);
$token_sistema = trim($ini['token'] ?? '');
$admin_id = (int)($ini['admin_id'] ?? 0);
$mp_token = trim($ini['mp_token'] ?? '');
$preco_premium = (float)($ini['preco_premium'] ?? 19.00);

if (!$token_sistema || !$mp_token || !$admin_id) {
    echo "❌ ERRO: Dados incompletos no dadosBot.ini!\n";
    exit;
}

$api = "https://api.telegram.org/bot$token_sistema/";
$api_mp = "https://api.mercadopago.com/v1/payments";

// BANCO DE DADOS
$db = new SQLite3('dados.db');
$db->exec("CREATE TABLE IF NOT EXISTS usuarios (
    id INTEGER PRIMARY KEY,
    token_bot TEXT DEFAULT '',
    grupo_id TEXT DEFAULT '',
    id_shopee TEXT DEFAULT '',
    id_mercadolivre TEXT DEFAULT '',
    id_magalu TEXT DEFAULT '',
    plano TEXT DEFAULT 'gratis',
    pagamento_id TEXT DEFAULT '',
    data_cadastro TEXT DEFAULT CURRENT_TIMESTAMP
)");

$db->exec("CREATE TABLE IF NOT EXISTS pagamentos (
    id TEXT PRIMARY KEY,
    usuario_id INTEGER,
    valor REAL,
    status TEXT DEFAULT 'pendente',
    criado_em TEXT DEFAULT CURRENT_TIMESTAMP
)");

echo "✅ BOT INICIADO! Aguardando mensagens...\n";

$offset = 0;
$esperando = [];
$ultima_verificacao = time();
$ultima_lista_msg = [];

// PLATAFORMAS
$plataformas = [
    'shopee' => ['nome' => '🛒 Shopee', 'campo' => 'id_shopee'],
    'mercadolivre' => ['nome' => '📦 Mercado Livre', 'campo' => 'id_mercadolivre'],
    'magalu' => ['nome' => '🟦 Magalu', 'campo' => 'id_magalu']
];

function identificarPlataforma($link) {
    $link = strtolower($link);
    if (str_contains($link, 'shopee')) return 'shopee';
    if (str_contains($link, 'mercadolivre') || str_contains($link, 'meli.la') || str_contains($link, 'mercadopago')) {
        return 'mercadolivre';
    }
    if (str_contains($link, 'magalu') || str_contains($link, 'magazine')) return 'magalu';
    return null;
}

function requisicao($url, $dados, $tk='', $metodo='POST') {
    $ch = curl_init($url);
    $h = ["Content-Type: application/json"];
    if ($tk) $h[] = "Authorization: Bearer $tk";
    curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    if ($metodo==='POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dados));
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $r = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $r;
}

function enviar($cid, $txt, $kbd, $api) {
    $d = ['chat_id'=>$cid, 'text'=>$txt, 'parse_mode'=>'HTML'];
    if ($kbd) $d['reply_markup'] = $kbd;
    return requisicao($api."sendMessage", $d);
}

function mostrarBotoesPlataformas($cid, $db, $plataformas, $api, &$ultima_lista_msg) {
    $usr = $db->querySingle("SELECT * FROM usuarios WHERE id = $cid", true);
    $msg = "🆔 <b>SEUS IDs DE AFILIADO</b>\n\n";
    $msg .= "Escolha uma plataforma:";
    $teclado = ['inline_keyboard' => []];
    foreach ($plataformas as $k => $p) {
        $tem = !empty($usr[$p['campo']]);
        $icone = $tem ? "✅" : "❌";
        $teclado['inline_keyboard'][] = [[
            'text' => "$icone {$p['nome']}",
            'callback_data' => "plataforma|$k"
        ]];
    }
    if (!empty($ultima_lista_msg[$cid])) {
        requisicao($api."deleteMessage", [
            'chat_id' => $cid,
            'message_id' => $ultima_lista_msg[$cid]
        ]);
    }
    $res = enviar($cid, $msg, $teclado, $api);
    if (!empty($res['result']['message_id'])) {
        $ultima_lista_msg[$cid] = $res['result']['message_id'];
    }
}

function gerarLinkAfiliado($link, $usr) {
    $p = identificarPlataforma($link);
    if (!$p) return $link;
    if ($p === 'shopee' && !empty($usr['id_shopee'])) return $link."?afiliado={$usr['id_shopee']}";
    if ($p === 'mercadolivre' && !empty($usr['id_mercadolivre'])) {
        $separador = str_contains($link, '?') ? '&' : '?';
        return $link.$separador."aff_id={$usr['id_mercadolivre']}";
    }
    if ($p === 'magalu' && !empty($usr['id_magalu'])) return $link."?id={$usr['id_magalu']}";
    return $link;
}

function criarPagamentoMP($uid, $valor, $api_mp, $tk, $db) {
    $d = ['transaction_amount'=>$valor, 'description'=>'Plano Premium', 'payment_method_id'=>'pix', 'payer'=>['email'=>"u$uid@bot.com"]];
    $r = requisicao($api_mp, $d, $tk);
    if (!empty($r['id'])) {
        $qr = $r['point_of_interaction']['transaction_data']['qr_code'] ?? ($r['qr_code'] ?? '');
        $db->exec("INSERT OR REPLACE INTO pagamentos VALUES ('{$r['id']}', $uid, $valor, 'pendente')");
        return ['id'=>$r['id'], 'qr_code'=>$qr];
    }
    return null;
}

function verificarPagamentos($db, $api, $admin, $tk) {
    $res = $db->query("SELECT * FROM pagamentos WHERE status='pendente'");
    while ($row = $res->fetchArray()) {
        $r = requisicao("https://api.mercadopago.com/v1/payments/{$row['id']}", [], $tk, 'GET');
        if (!empty($r['status'])) {
            if ($r['status']==='approved' && $row['status']!=='aprovado') {
                $db->exec("UPDATE pagamentos SET status='aprovado' WHERE id='{$row['id']}'");
                $db->exec("UPDATE usuarios SET plano='premium' WHERE id={$row['usuario_id']}");
                enviar($row['usuario_id'], "🎉 PAGAMENTO CONFIRMADO! PREMIUM ✅", [], $api);
                enviar($admin, "📢 NOVO PREMIUM — {$row['usuario_id']} — R$ ".number_format($row['valor'],2,',',''), [], $api);
            } elseif ($r['status']==='rejected' && $row['status']==='pendente') {
                $db->exec("UPDATE pagamentos SET status='recusado' WHERE id='{$row['id']}'");
                enviar($row['usuario_id'], "❌ Pagamento recusado.", [], $api);
            }
        }
    }
}

while (true) {
    if (time() - $ultima_verificacao > 15) {
        verificarPagamentos($db, $api, $admin_id, $mp_token);
        $ultima_verificacao = time();
    }
    $ch = curl_init($api."getUpdates?offset=$offset&timeout=15");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = curl_exec($ch);
    curl_close($ch);
    $updates = json_decode($res, true);
    if (!empty($updates['result'])) {
        foreach ($updates['result'] as $u) {
            $offset = $u['update_id'] + 1;
            if (!empty($u['message'])) {
                $cid = $u['message']['chat']['id'];
                $texto = trim($u['message']['text'] ?? '');
                $callback = '';
            } elseif (!empty($u['callback_query'])) {
                $cid = $u['callback_query']['message']['chat']['id'];
                $texto = '';
                $callback = $u['callback_query']['data'];
                requisicao($api."answerCallbackQuery", ['callback_query_id' => $u['callback_query']['id']]);
            } else {
                continue;
            }
            echo "📩 $cid → $texto\n";
            $usr = $db->querySingle("SELECT * FROM usuarios WHERE id = $cid", true);
            if (!$usr) {
                $db->exec("INSERT INTO usuarios (id) VALUES ($cid)");
                $usr = $db->querySingle("SELECT * FROM usuarios WHERE id = $cid", true);
            }
            $plano = $usr['plano'];

            if ($texto === '/start' || $texto === '🏠 Início') {
                $cad = [];
                foreach ($plataformas as $k => $p) {
                    $cad[$k] = !empty($usr[$p['campo']]);
                }
                $tem_id = in_array(true, $cad);
                $msg = "🛍️ <b>BOT DE AFILIADOS</b>\n\n";
                $msg .= "Seu plano: " . ($plano === 'premium' ? "⭐ PREMIUM" : "🆓 GRATUITO") . "\n";
                $msg .= "IDs cadastrados: " . ($tem_id ? "✅ Sim" : "❌ Não") . "\n\n";
                $msg .= "Escolha o que deseja:";
                $teclado = [
                    'keyboard' => [
                        ['🔗 Converter Link'],
                        ['🆔 Meus IDs'],
                        ['⚙️ Configurar Dados'],
                        ['⭐ Assinar Premium', '❓ Ajuda']
                    ],
                    'resize_keyboard' => true
                ];
                unset($ultima_lista_msg[$cid]);
                enviar($cid, $msg, $teclado, $api);
            }
            elseif ($texto === '🆔 Meus IDs' || $callback === 'meus_ids') {
                mostrarBotoesPlataformas($cid, $db, $plataformas, $api, $ultima_lista_msg);
            }
            elseif (str_starts_with($callback, 'plataforma|')) {
                list(, $plat) = explode('|', $callback);
                $usr = $db->querySingle("SELECT * FROM usuarios WHERE id = $cid", true);
                $tem_cadastro = !empty($usr[$plataformas[$plat]['campo']]);
                if (!$tem_cadastro) {
                    $msg = "➕ <b>{$plataformas[$plat]['nome']}</b>\n\n";
                    $msg .= "Digite abaixo o seu ID da {$plataformas[$plat]['nome']}:";
                    $esperando[$cid] = "cadastrar|$plat";
                    enviar($cid, $msg, [], $api);
                } else {
                    $msg = "✅ <b>{$plataformas[$plat]['nome']} — CADASTRADO</b>\n\n";
                    $msg .= "Seu ID:\n<code>{$usr[$plataformas[$plat]['campo']]}</code>\n\n";
                    $msg .= "Deseja alterar/editar esse ID?";
                    $teclado = ['inline_keyboard' => [
                        [['text' => '✏️ Editar ID', 'callback_data' => "editar|$plat"]],
                        [['text' => '🔙 Voltar', 'callback_data' => 'meus_ids']]
                    ]];
                    enviar($cid, $msg, $teclado, $api);
                }
            }
            elseif (isset($esperando[$cid]) && str_starts_with($esperando[$cid], 'cadastrar|')) {
                list(, $plat) = explode('|', $esperando[$cid]);
                $id = trim($texto);
                if (strlen($id) < 3) {
                    enviar($cid, "❌ ID muito curto! Mínimo 3 caracteres.\n\nDigite novamente:", [], $api);
                    continue;
                }
                $db->exec("UPDATE usuarios SET {$plataformas[$plat]['campo']} = '" . SQLite3::escapeString($id) . "' WHERE id = $cid");
                unset($esperando[$cid]);
                $msg = "✅ <b>CADASTRADO COM SUCESSO!</b>\n\n";
                $msg .= "{$plataformas[$plat]['nome']}\n";
                $msg .= "ID: <code>$id</code>";
                enviar($cid, $msg, [], $api);
                sleep(1);
                mostrarBotoesPlataformas($cid, $db, $plataformas, $api, $ultima_lista_msg);
            }
            elseif (str_starts_with($callback, 'editar|')) {
                list(, $plat) = explode('|', $callback);
                $esperando[$cid] = "editando|$plat";
                $msg = "✏️ <b>EDITAR — {$plataformas[$plat]['nome']}</b>\n\n";
                $msg .= "Digite o NOVO ID:";
                enviar($cid, $msg, [], $api);
            }
            elseif (isset($esperando[$cid]) && str_starts_with($esperando[$cid], 'editando|')) {
                $id = trim($texto);
                if (strlen($id) < 3) {
                    enviar($cid, "❌ ID muito curto! Mínimo 3 caracteres.\n\nDigite novamente:", [], $api);
                    continue;
                }
                $db->exec("UPDATE usuarios SET {$plataformas[$plat]['campo']} = '" . SQLite3::escapeString($id) . "' WHERE id = $cid");
                unset($esperando[$cid]);
                $msg = "✅ <b>ATUALIZADO!</b>\n\n";
                $msg .= "{$plataformas[$plat]['nome']}\n";
                $msg .= "Novo ID: <code>$id</code>";
                enviar($cid, $msg, [], $api);
                sleep(1);
                mostrarBotoesPlataformas($cid, $db, $plataformas, $api, $ultima_lista_msg);
            }
            elseif ($texto === '🔗 Converter Link' || str_starts_with($texto, 'http')) {
                if ($texto === '🔗 Converter Link') {
                    $usr = $db->querySingle("SELECT * FROM usuarios WHERE id = $cid", true);
                    $tem_id = !empty($usr['id_shopee']) || !empty($usr['id_mercadolivre']) || !empty($usr['id_magalu']);
                    if (!$tem_id) {
                        enviar($cid, "⚠️ Nenhum ID cadastrado!\n\nClique em 🆔 Meus IDs → cadastre primeiro.", [], $api);
                        continue;
                    }
                    enviar($cid, "📎 Cole o link do produto:", [], $api);
                    continue;
                }
                $plat = identificarPlataforma($texto);
                if (!$plat) {
                    enviar($cid, "❌ Plataforma não reconhecida!\nSuportadas: Shopee | Mercado Livre (mercadolivre.com + meli.la) | Magalu", [], $api);
                    continue;
                }
                $usr = $db->querySingle("SELECT * FROM usuarios WHERE id = $cid", true);
                if (empty($usr[$plataformas[$plat]['campo']])) {
                    enviar($cid, "⚠️ ID não cadastrado para {$plataformas[$plat]['nome']}!\nCadastre em 🆔 Meus IDs primeiro.", [], $api);
                    continue;
                }
                $link_conv = gerarLinkAfiliado($texto, $usr);
                $resp = "✅ <b>CONVERTIDO — {$plataformas[$plat]['nome']}</b>\n\n";
                $resp .= "Original:\n<code>$texto</code>\n\nSeu link:\n<code>$link_conv</code>";
                if ($plano === 'premium' && !empty($usr['grupo_id'])) {
                    $api_grupo = !empty($usr['token_bot']) ? "https://api.telegram.org/bot{$usr['token_bot']}/" : $api;
                    requisicao($api_grupo."sendMessage", [
                        'chat_id' => $usr['grupo_id'],
                        'text' => "🔥 OFERTA!\n\n$link_conv",
                        'parse_mode' => 'HTML'
                    ]);
                    $resp .= "\n✅ Enviado ao grupo!";
                }
                enviar($cid, $resp, [], $api);
            }
            elseif ($texto === '⚙️ Configurar Dados') {
                if ($plano !== 'premium') {
                    enviar($cid, "⭐ Exclusivo Premium — R$ 19,00/mês", [
                        'inline_keyboard' => [[['text' => '💳 Assinar', 'callback_data' => 'comprar_premium']]]
                    ], $api);
                    continue;
                }
                $esperando[$cid] = 'grupo';
                enviar($cid, "Digite o ID do Grupo (-100...):", [], $api);
            }
            elseif ($esperando[$cid] === 'grupo') {
                if (!preg_match('/^-100\d+$/', $texto)) {
                    enviar($cid, "❌ Inválido! Ex: -100123456789\n\nTente novamente:", [], $api);
                    continue;
                }
                $db->exec("UPDATE usuarios SET grupo_id = '" . SQLite3::escapeString($texto) . "' WHERE id = $cid");
                unset($esperando[$cid]);
                enviar($cid, "✅ Pronto! Envie o link para começar 🚀", [], $api);
            }
            elseif ($texto === '⭐ Assinar Premium' || $callback === 'comprar_premium') {
                $pag = criarPagamentoMP($cid, $preco_premium, $api_mp, $mp_token, $db);
                if ($pag && !empty($pag['qr_code'])) {
                    enviar($cid, "⭐ PREMIUM — R$ {$preco_premium}/mês\n\n💳 PIX:\n<code>{$pag['qr_code']}</code>\n\nConfirmação em até 1min ⏳", [], $api);
                } else {
                    enviar($cid, "❌ Erro ao gerar PIX.", [], $api);
                }
            }
            elseif ($texto === '❓ Ajuda') {
                enviar($cid, "📖 Como usar:\n1️⃣ 🆔 Meus IDs → cadastre seus IDs\n2️⃣ 🔗 Converter Link → cole o link\n   ✅ Mercado Livre: mercadolivre.com + meli.la\n3️⃣ Sistema detecta e aplica seu ID\n\n⚠️ Confira sempre o ID cadastrado!", [], $api);
            }
        }
    }
    sleep(1);
}
