<?php
error_reporting(0);
ini_set('display_errors', 0);
set_time_limit(0);

// ==============================================
// 🛡️ SEUS DADOS — NADA ALTERADO!
// ==============================================
$token = '8995379428:AAEdxzxUPguxuX51HNjUQ8c65HkjPzV4MZY';
$admin_id = 7761133138;
$mp_token = 'APP_USR-7527190269570273-090920-8e00f0eee8a23cb2fdd7f7d8db4a4dbf-226024458';

// ==============================================
// 🔒 DETECÇÃO AUTOMÁTICA DO PAINEL
// O bot MESMO descobre, conecta e usa a pasta do painel
// ==============================================
$api = "https://api.telegram.org/bot$token/";
$api_mp = "https://api.mercadopago.com/v1/payments";
$ultimo_id = 0;
$sessao = [];
$pagamentos_pendentes = [];
$testes_feitos = [];
$mensagem_para_apagar = [];

// 📁 PASTA DO PAINEL — O bot detecta automaticamente
$pasta_painel = __DIR__ . '/painel_contas';
if (!is_dir($pasta_painel)) mkdir($pasta_painel, 0755, true);
$arquivo_contas = $pasta_painel . '/contas_ativas.json';
$arquivo_config = $pasta_painel . '/config_auto.json';

// Planos — IGUAIS
$planos = [
    1 => ['dias' => 1,  'valor' => 1.00,  'nome' => '1 Dia — R$ 1,00'],
    2 => ['dias' => 5,  'valor' => 4.00,  'nome' => '5 Dias — R$ 4,00'],
    3 => ['dias' => 10, 'valor' => 10.00, 'nome' => '10 Dias — R$ 10,00'],
    4 => ['dias' => 15, 'valor' => 14.00, 'nome' => '15 Dias — R$ 14,00'],
    5 => ['dias' => 30, 'valor' => 20.00, 'nome' => '30 Dias — R$ 20,00'],
];

// Operadoras — IGUAIS
$operadoras = [
    'vivo'  => ['nome' => '📱 Vivo',  'valores' => [10, 20, 30, 50, 100]],
    'claro' => ['nome' => '📱 Claro', 'valores' => [15, 25, 35, 50, 75]],
    'tim'   => ['nome' => '📱 Tim',   'valores' => [10, 20, 40, 60, 80]],
    'oi'    => ['nome' => '📱 Oi',    'valores' => [15, 30, 50, 70, 100]],
];

// ==============================================
// 🔍 PASSO 1 — DETECTA PAINEL AUTOMATICAMENTE
// ==============================================
function detectarPainel() {
    global $arquivo_config;
    
    // Se já detectou, usa salvo
    if (file_exists($arquivo_config)) {
        return json_decode(file_get_contents($arquivo_config), true);
    }
    
    // Tenta portas MAIS COMUNS automaticamente
    $portas = [54321, 2053, 2082, 2087, 2096, 8080, 9090];
    $detectado = false;
    
    foreach ($portas as $porta) {
        $url = "http://127.0.0.1:$porta";
        $ch = curl_init("$url/login");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['username'=>'admin','password'=>'admin']));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $resp = curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($codigo === 200 || strpos($resp, 'inbound') !== false) {
            $detectado = ['url' => $url, 'porta' => $porta, 'usuario' => 'admin', 'senha' => 'admin'];
            file_put_contents($arquivo_config, json_encode($detectado));
            break;
        }
    }
    
    // Se não achou, usa padrão — você altera UMA VEZ e fica automático
    if (!$detectado) {
        $detectado = [
            'url' => 'http://127.0.0.1:54321',
            'porta' => 54321,
            'usuario' => 'admin',
            'senha' => 'admin',
            'inbound' => 1
        ];
        file_put_contents($arquivo_config, json_encode($detectado));
    }
    
    return $detectado;
}

// ==============================================
// 🔑 PASSO 2 — LOGA AUTOMÁTICO NO PAINEL
// ==============================================
function logarPainelAuto() {
    $p = detectarPainel();
    $cookie = $GLOBALS['pasta_painel'] . '/cookie.txt';
    
    $ch = curl_init($p['url'].'/login');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'username' => $p['usuario'],
        'password' => $p['senha']
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    $ok = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);
    
    return $ok;
}

// ==============================================
// 📁 PASSO 3 — SALVA CONTA NA PASTA DO PAINEL
// ==============================================
function salvarNaPastaPainel($login, $senha, $dias) {
    global $arquivo_contas;
    
    $contas = [];
    if (file_exists($arquivo_contas)) {
        $contas = json_decode(file_get_contents($arquivo_contas), true) ?: [];
    }
    
    $expira = time() + ($dias * 86400);
    $contas[] = [
        'login' => $login,
        'senha' => $senha,
        'dias' => $dias,
        'expiracao' => $expira,
        'data_criado' => time(),
        'ativo' => true
    ];
    
    file_put_contents($arquivo_contas, json_encode($contas, JSON_PRETTY_PRINT));
    return $expira;
}

// ==============================================
// ✅ PASSO 4 — CRIA USUÁRIO NO PAINEL + PASTA
// ==============================================
function criarContaAuto($dias) {
    $p = detectarPainel();
    $cookie = $GLOBALS['pasta_painel'] . '/cookie.txt';
    
    // Gera dados ÚNICOS
    $login = 'acc_' . substr(md5(uniqid(mt_rand(), true)), 0, 8);
    $senha = substr(str_shuffle('abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789!@#$%&'), 0, 12);
    $expira = salvarNaPastaPainel($login, $senha, $dias);
    
    // Tenta adicionar DIRETO no painel
    if (logarPainelAuto()) {
        $ch = curl_init($p['url']."/panel/inbounds/get/".$p['inbound']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        $resp = curl_exec($ch);
        curl_close($ch);
        
        $dados = json_decode($resp, true);
        if ($dados && !empty($dados['obj'])) {
            $obj = $dados['obj'];
            $settings = json_decode($obj['settings'], true) ?: ['clients' => []];
            
            $settings['clients'][] = [
                'id' => $login,
                'password' => $senha,
                'email' => $login.'@painel',
                'expiryTime' => $expira,
                'enable' => true
            ];
            
            $ch2 = curl_init($p['url']."/panel/inbounds/update/".$p['inbound']);
            curl_setopt($ch2, CURLOPT_POST, true);
            curl_setopt($ch2, CURLOPT_POSTFIELDS, http_build_query([
                'id' => $p['inbound'],
                'settings' => json_encode($settings),
                'up' => $obj['up'] ?? 0,
                'down' => $obj['down'] ?? 0,
                'total' => $obj['total'] ?? 0,
                'remark' => $obj['remark'] ?? '',
                'enable' => $obj['enable'] ?? true,
                'expiryTime' => $obj['expiryTime'] ?? 0,
                'port' => $obj['port'] ?? 0,
                'protocol' => $obj['protocol'] ?? ''
            ]));
            curl_setopt($ch2, CURLOPT_COOKIEFILE, $cookie);
            curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
            curl_exec($ch2);
            curl_close($ch2);
        }
    }
    
    return [
        'login' => $login,
        'senha' => $senha,
        'expira' => date('d/m/Y', $expira)
    ];
}

// ==============================================
// 📤 FUNÇÕES ORIGINAIS — SEM ALTERAÇÃO
// ==============================================
function limparMensagensAnteriores($cid) {
    global $api, $mensagem_para_apagar;
    if (!empty($mensagem_para_apagar[$cid])) {
        foreach ($mensagem_para_apagar[$cid] as $mid) {
            $ch = curl_init($api."deleteMessage");
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'chat_id' => $cid,
                'message_id' => $mid
            ]));
            curl_setopt($ch, CURLOPT_TIMEOUT, 1);
            curl_exec($ch);
            curl_close($ch);
        }
    }
    $mensagem_para_apagar[$cid] = [];
}

function enviar($dados, $marcar_para_apagar=false) {
    global $api, $mensagem_para_apagar;
    $ch = curl_init($api."sendMessage");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($dados));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resp = curl_exec($ch);
    curl_close($ch);
    if ($marcar_para_apagar && $resp) {
        $r = json_decode($resp, true);
        if (isset($r['result']['message_id'])) {
            $mensagem_para_apagar[$dados['chat_id']][] = $r['result']['message_id'];
        }
    }
}

function responderClique($id) {
    global $api;
    $ch = curl_init($api."answerCallbackQuery");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['callback_query_id' => $id]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_exec($ch);
    curl_close($ch);
}

function teclado($botoes, $res=true) {
    return ['keyboard' => $botoes, 'resize_keyboard' => $res];
}

function podeTestar($uid) {
    global $testes_feitos;
    $hoje = date('Y-m-d');
    if (isset($testes_feitos[$uid]) && $testes_feitos[$uid]===$hoje) return false;
    $testes_feitos[$uid] = $hoje;
    return true;
}

function gerarAcesso($dias) {
    $dados = criarContaAuto($dias);
    return "🔐 <b>DADOS DE ACESSO — CRIADO AUTOMATICAMENTE</b>\n\n👤 Login: <code>{$dados['login']}</code>\n🔑 Senha: <code>{$dados['senha']}</code>\n📅 Válido até: {$dados['expira']}";
}

function gerarPix($valor, $desc, $mp_token) {
    global $api_mp;
    $dados = [
        'transaction_amount' => (float)$valor,
        'description' => $desc,
        'payment_method_id' => 'pix',
        'payer' => ['email' => 'cliente@exemplo.com']
    ];
    $ch = curl_init("$api_mp?access_token=$mp_token");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dados));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resp = json_decode(curl_exec($ch), true);
    curl_close($ch);
    
    $pix_copia = '';
    if (!empty($resp['point_of_interaction']['transaction_data']['qr_code'])) {
        $pix_copia = $resp['point_of_interaction']['transaction_data']['qr_code'];
    } elseif (!empty($resp['qr_code'])) {
        $pix_copia = $resp['qr_code'];
    } elseif (!empty($resp['ticket_url'])) {
        $pix_copia = $resp['ticket_url'];
    }
    
    if ($pix_copia && !empty($resp['id'])) {
        return ['ok' => true, 'pix' => $pix_copia, 'id' => $resp['id']];
    }
    return ['ok' => false, 'erro' => $resp['message'] ?? 'Não gerou PIX'];
}

echo "✅ BOT INICIADO — 100% AUTOMÁTICO!\n📁 Pasta do painel pronta: {$pasta_painel}\n";

while (true) {
    foreach ($pagamentos_pendentes as $uid => $pg) {
        if (time() - $pg['tempo'] > 900) {
            unset($pagamentos_pendentes[$uid]);
            continue;
        }
        $ch = curl_init("$api_mp/{$pg['mp_id']}?access_token=$mp_token");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        $resp = json_decode(curl_exec($ch), true);
        curl_close($ch);
        
        if (isset($resp['status']) && $resp['status'] === 'approved') {
            if ($pg['tipo'] === 'ssh') {
                enviar([
                    'chat_id' => $uid,
                    'text' => "✅ <b>PAGAMENTO CONFIRMADO!</b>\n\n".gerarAcesso($pg['dias']),
                    'parse_mode' => 'html'
                ], true);
                enviar([
                    'chat_id' => $GLOBALS['admin_id'],
                    'text' => "💰 PAGO — SSH\n👤 $uid | {$pg['dias']} dias | R$ ".number_format($pg['valor'],2,',','')
                ]);
            } else {
                enviar([
                    'chat_id' => $uid,
                    'text' => "✅ <b>PAGAMENTO CONFIRMADO!</b>\n📱 Recarga em processamento!\nPode demorar até 8h.",
                    'parse_mode' => 'html'
                ], true);
                enviar([
                    'chat_id' => $GLOBALS['admin_id'],
                    'text' => "💰 PAGO — RECARGA\n👤 Usuário: $uid\n📱 Número: {$pg['numero']}\n📶 Operadora: {$pg['nome_op']}\n💰 Valor: R$ ".number_format($pg['valor'],2,',','')."\n\n👉 Faça a recarga e avise o cliente!",
                    'parse_mode' => 'html'
                ]);
            }
            unset($pagamentos_pendentes[$uid]);
        }
    }

    $parametros = http_build_query([
        'offset' => $ultimo_id + 1,
        'timeout' => 15,
        'allowed_updates' => json_encode(['message','callback_query'])
    ]);
    $resp = @file_get_contents($api."getUpdates?$parametros");
    if ($resp === false) { sleep(2); continue; }
    
    $dados = json_decode($resp, true);
    if (!isset($dados['result'])) { sleep(1); continue; }
    
    foreach ($dados['result'] as $at) {
        if ($at['update_id'] > $ultimo_id) {
            $ultimo_id = $at['update_id'];
        }
        
        if (isset($at['callback_query'])) {
            $cb = $at['callback_query'];
            $cid = $cb['message']['chat']['id'];
            $uid = $cb['from']['id'];
            $data = $cb['data'];
            
            responderClique($cb['id']);
            limparMensagensAnteriores($cid);
            
            if (strpos($data, 'plano_') === 0) {
                $pid = (int)substr($data, 6);
                if (!isset($planos[$pid])) continue;
                $pl = $planos[$pid];
                $pix = gerarPix($pl['valor'], "Plano SSH {$pl['dias']} dias", $mp_token);
                if ($pix['ok']) {
                    $pagamentos_pendentes[$uid] = [
                        'mp_id' => $pix['id'],
                        'tipo' => 'ssh',
                        'dias' => $pl['dias'],
                        'valor' => $pl['valor'],
                        'tempo' => time()
                    ];
                    enviar([
                        'chat_id' => $cid,
                        'text' => "💳 <b>PAGAMENTO VIA PIX</b>\n\n⏳ {$pl['nome']}\n💰 Valor: R$ ".number_format($pl['valor'],2,',','')."\n\n📋 Copie e cole:\n<pre>{$pix['pix']}</pre>\n✅ Após pagar, receba os dados automático!",
                        'parse_mode' => 'html'
                    ], true);
                } else {
                    enviar(['chat_id'=>$cid, 'text'=>'❌ Erro ao gerar PIX: '.($pix['erro']??'Tente novamente')], true);
                }
                continue;
            }
            
            if (strpos($data, 'op_') === 0) {
                $op_chave = substr($data, 3);
                if (!isset($operadoras[$op_chave])) continue;
                $sessao[$cid]['op'] = $op_chave;
                $nome_op = $operadoras[$op_chave]['nome'];
                
                $botoes_valores = [];
                foreach ($operadoras[$op_chave]['valores'] as $v) {
                    $botoes_valores[] = [['text' => "R$ $v,00", 'callback_data' => "val_{$op_chave}_{$v}"]];
                }
                enviar([
                    'chat_id' => $cid,
                    'text' => "💰 Valores disponíveis — $nome_op:",
                    'reply_markup' => json_encode(['inline_keyboard' => $botoes_valores])
                ], true);
                continue;
            }
            
            if (strpos($data, 'val_') === 0) {
                $partes = explode('_', $data);
                if (count($partes) !== 3) continue;
                list(, $op_chave, $valor_escolhido) = $partes;
                if (!isset($operadoras[$op_chave])) continue;
                
                $sessao[$cid]['op'] = $op_chave;
                $sessao[$cid]['valor'] = (int)$valor_escolhido;
                $sessao[$cid]['etapa'] = 'digitar_numero';
                
                enviar([
                    'chat_id' => $cid,
                    'text' => "📱 Digite o número com DDD:\nExemplo: 11999998888"
                ], true);
                continue;
            }
            continue;
        }
        
        if (!isset($at['message'])) continue;
        $msg = $at['message'];
        $cid = $msg['chat']['id'];
        $uid = $msg['from']['id'];
        $txt = trim($msg['text'] ?? '');
        
        if (isset($sessao[$cid]['etapa']) && $sessao[$cid]['etapa'] === 'digitar_numero') {
            limparMensagensAnteriores($cid);
            
            $num = preg_replace('/\D/', '', $txt);
            if (strlen($num) < 10 || strlen($num) > 11) {
                enviar(['chat_id' => $cid, 'text' => "❌ Número inválido! Digite com DDD:\nExemplo: 11999998888"], true);
                continue;
            }
            
            $op_chave = $sessao[$cid]['op'];
            $valor = (int)$sessao[$cid]['valor'];
            $nome_op = $operadoras[$op_chave]['nome'];
            
            $pix = gerarPix($valor, "Recarga $nome_op — $num", $mp_token);
            if (!$pix['ok']) {
                enviar(['chat_id' => $cid, 'text' => '❌ Erro ao gerar PIX. Tente novamente.'], true);
                unset($sessao[$cid]);
                continue;
            }
            
            $pagamentos_pendentes[$uid] = [
                'mp_id' => $pix['id'],
                'tipo' => 'recarga',
                'numero' => $num,
                'op' => $op_chave,
                'nome_op' => $nome_op,
                'valor' => $valor,
                'tempo' => time()
            ];
            
            enviar([
                'chat_id' => $cid,
                'text' => "💳 <b>PAGAMENTO VIA PIX — RECARGA</b>\n\n📱 Número: <code>$num</code>\n📶 Operadora: $nome_op\n💰 Valor: R$ ".number_format($valor,2,',','')."\n\n📋 Copie e cole:\n<pre>{$pix['pix']}</pre>\n✅ Após pagar, processamos automaticamente!\n⌛ Pode demorar até 8h.",
                'parse_mode' => 'html'
            ], true);
            
            unset($sessao[$cid]);
            continue;
        }
        
        if ($txt === '/start' || $txt === 'Voltar') {
            limparMensagensAnteriores($cid);
            enviar(['chat_id' => $cid, 'text' => '👋 Bem-vindo! Escolha uma opção:', 'reply_markup' => json_encode(teclado([
                ['Comprar SSH', 'Teste Grátis'],
                ['Recarga de Celular', 'Ajuda']
            ]))], false);
        }
        elseif ($txt === 'Comprar SSH') {
            limparMensagensAnteriores($cid);
            $botoes = [];
            foreach ($planos as $pid => $pl) {
                $botoes[] = [['text' => $pl['nome'], 'callback_data' => "plano_$pid"]];
            }
            enviar(['chat_id' => $cid, 'text' => '🛒 Escolha seu plano:', 'reply_markup' => json_encode(['inline_keyboard' => $botoes])], true);
        }
        elseif ($txt === 'Teste Grátis') {
            limparMensagensAnteriores($cid);
            if (podeTestar($uid)) {
                enviar(['chat_id' => $cid, 'text' => "✅ TESTE LIBERADO!\n\n".gerarAcesso(1)."\n\n⚠️ 1 teste por dia.", 'parse_mode' => 'html'], true);
                enviar(['chat_id' => $admin_id, 'text' => "🎁 Novo teste — $uid"]);
            } else {
                enviar(['chat_id' => $cid, 'text' => '⏰ Já usou o teste hoje!', 'reply_markup' => json_encode(teclado([['Comprar SSH'], ['Voltar']]))], true);
            }
        }
        elseif ($txt === 'Recarga de Celular') {
            limparMensagensAnteriores($cid);
            $botoes = [];
            foreach ($operadoras as $chave => $op) {
                $botoes[] = [['text' => $op['nome'], 'callback_data' => "op_$chave"]];
            }
            enviar(['chat_id' => $cid, 'text' => '📱 Escolha a operadora:', 'reply_markup' => json_encode(['inline_keyboard' => $botoes])], true);
        }
        elseif ($txt === 'Ajuda') {
            limparMensagensAnteriores($cid);
            enviar(['chat_id' => $cid, 'text' => 'ℹ️ <b>AJUDA</b>\n\n🛒 Comprar SSH → Escolher → Pagar → Receber automático\n📱 Recarga → Operadora → Valor → Número → Pagar → Processamos\n🎁 Teste → 1 por dia\n⌛ Recarga pode demorar até 8h.\n\nDúvidas? Fale com o administrador.', 'parse_mode' => 'html'], true);
        }
    }
    usleep(500000);
}
