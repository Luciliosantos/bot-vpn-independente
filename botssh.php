<?php
error_reporting(0);
ini_set('display_errors', 0);
set_time_limit(0);

// ==============================================
// SEUS DADOS — TUDO AQUI
// ==============================================
$token = '8591852336:AAHHK2tuPC0tjJK9G8gcjBk8x2FxylSVQu8'; // ✅ NOVO TOKEN
$admin_id = 7761133138;
$mp_token = 'APP_USR-7527190269570273-090920-8e00f0eee8a23cb2fdd7f7d8db4a4dbf-226024458';

$api = "https://api.telegram.org/bot$token/";
$api_mp = "https://api.mercadopago.com/v1/payments";
$offset = 0;
$sessao = [];
$pagamentos_pendentes = [];
$testes_feitos = [];
$mensagem_para_apagar = [];

// Planos SSH
$planos = [
    1 => ['dias' => 1,  'valor' => 1.00,  'nome' => '1 Dia — R$ 1,00'],
    2 => ['dias' => 5,  'valor' => 4.00,  'nome' => '5 Dias — R$ 4,00'],
    3 => ['dias' => 10, 'valor' => 10.00, 'nome' => '10 Dias — R$ 10,00'],
    4 => ['dias' => 15, 'valor' => 14.00, 'nome' => '15 Dias — R$ 14,00'],
    5 => ['dias' => 30, 'valor' => 20.00, 'nome' => '30 Dias — R$ 20,00'],
];

// Operadoras de recarga
$operadoras = [
    'vivo'  => ['nome' => '📱 Vivo',  'valores' => [10, 20, 30, 50, 100]],
    'claro' => ['nome' => '📱 Claro', 'valores' => [15, 25, 35, 50, 75]],
    'tim'   => ['nome' => '📱 Tim',   'valores' => [10, 20, 40, 60, 80]],
    'oi'    => ['nome' => '📱 Oi',    'valores' => [15, 30, 50, 70, 100]],
];

// ==============================================
// PEGA IP PÚBLICO DA VPS
// ==============================================
function getIpPublic(){
    $ch = curl_init("https://api.ipify.org");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $ip = trim(curl_exec($ch));
    curl_close($ch);
    return $ip ?: gethostbyname(gethostname());
}

// ==============================================
// CRIA USUÁRIO SSH REAL NA VPS
// ==============================================
function criarContaSSHReal($dias){
    $usuario = 'u'.substr(md5(uniqid(mt_rand(), true)), 0, 8);
    $senha = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%&'), 0, 10);
    $expira_data = date('d/m/Y', strtotime("+$dias days"));
    $ip_servidor = getIpPublic();
    
    $caminho_script = '/root/bot/gerarusuario.sh';
    
    if(file_exists($caminho_script)){
        @chmod($caminho_script, 0755);
        $comando = "/bin/bash $caminho_script $usuario $senha $dias 1";
        exec($comando, $saida, $codigo_saida);
        if($codigo_saida !== 0){
            return [
                'ok' => false,
                'erro' => 'Falha ao criar conta no sistema'
            ];
        }
    } else {
        // Fallback — cria direto se o script não existir
        exec("userdel -r $usuario 2>/dev/null");
        exec("useradd -M -s /bin/false $usuario");
        exec("echo '$usuario:$senha' | chpasswd");
        if($dias > 0){
            $data_exp = date('Y-m-d', strtotime("+$dias days"));
            exec("chage -E $data_exp $usuario");
        }
    }
    
    return [
        'ok' => true,
        'usuario' => $usuario,
        'senha' => $senha,
        'ip' => $ip_servidor,
        'dias' => $dias,
        'expira' => $expira_data,
        'texto' => "✅ <b>CONTA SSH CRIADA!</b>\n\n".
                   "🌐 Servidor: <code>$ip_servidor</code>\n".
                   "👤 Usuário: <code>$usuario</code>\n".
                   "🔑 Senha: <code>$senha</code>\n".
                   "📅 Válido até: <b>$expira_data</b>\n\n".
                   "✅ Conecte via SSH!"
    ];
}

function limparMensagensAnteriores($cid) {
    global $api, $mensagem_para_apagar;
    if (!empty($mensagem_para_apagar[$cid])) {
        foreach ($mensagem_para_apagar[$cid] as $mid) {
            $ch = curl_init($api."deleteMessage");
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'chat_id' => $cid, 'message_id' => $mid
            ]));
            curl_setopt($ch, CURLOPT_TIMEOUT, 1);
            curl_exec($ch); curl_close($ch);
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
    $resp = curl_exec($ch); curl_close($ch);
    if ($marcar_para_apagar && $resp) {
        $r = json_decode($resp, true);
        if (isset($r['result']['message_id'])) {
            $mensagem_para_apagar[$dados['chat_id']][] = $r['result']['message_id'];
        }
    }
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

function gerarPix($valor, $desc, $mp_token) {
    global $api_mp;
    $dados = [
        'transaction_amount' => (float)$valor,
        'description' => $desc,
        'payment_method_id' => 'pix',
        'payer' => [
            'email' => 'cliente_'.uniqid().'@gmail.com',
            'first_name' => 'Cliente',
            'last_name' => 'Telegram'
        ]
    ];
    $ch = curl_init($api_mp);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS => json_encode($dados));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer '.$mp_token
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $resp = json_decode(curl_exec($ch), true);
    curl_close($ch);
    
    if (isset($resp['point_of_interaction']['transaction_data']['qr_code']) && isset($resp['id'])) {
        return ['ok' => true, 'pix' => $resp['point_of_interaction']['transaction_data']['qr_code'], 'id' => $resp['id']];
    }
    return ['ok' => false, 'erro' => $resp['message'] ?? 'Não foi possível gerar o PIX'];
}

echo "✅ BOT INICIADO — SSH + PIX PRONTO!\n";

while (true) {
    // Verifica pagamentos
    foreach ($pagamentos_pendentes as $uid => $pg) {
        if (time() - $pg['tempo'] > 900) {
            unset($pagamentos_pendentes[$uid]);
            continue;
        }
        
        $ch = curl_init("$api_mp/{$pg['mp_id']}");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer '.$mp_token]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        $resp = json_decode(curl_exec($ch), true);
        curl_close($ch);
        
        if (isset($resp['status']) && $resp['status'] === 'approved') {
            if ($pg['tipo'] === 'ssh') {
                $conta = criarContaSSHReal($pg['dias']);
                if($conta['ok']){
                    enviar([
                        'chat_id' => $uid,
                        'text' => "✅ <b>PAGAMENTO CONFIRMADO!</b>\n\n".$conta['texto'],
                        'parse_mode' => 'html'
                    ], true);
                    enviar([
                        'chat_id' => $GLOBALS['admin_id'],
                        'text' => "💰 VENDA CONFIRMADA!\n👤 Cliente: $uid\n📋 Usuário: {$conta['usuario']}\n⏱️ Dias: {$pg['dias']}\n💵 R$ ".number_format($pg['valor'],2,',','')
                    ]);
                } else {
                    enviar(['chat_id'=>$uid, 'text'=>'✅ Pago! Erro ao criar conta — contate o suporte.', 'parse_mode'=>'html'], true);
                }
            } else {
                enviar([
                    'chat_id' => $uid,
                    'text' => "✅ <b>PAGAMENTO CONFIRMADO!</b>\n📱 Recarga em processamento!\nPode demorar até 8h.",
                    'parse_mode' => 'html'
                ], true);
                enviar([
                    'chat_id' => $GLOBALS['admin_id'],
                    'text' => "💰 RECARGA PAGA!\n👤 Usuário: $uid\n📱 Número: {$pg['numero']}\n📶 Operadora: {$pg['nome_op']}\n💰 Valor: R$ ".number_format($pg['valor'],2,',','')."\n\n👉 Faça a recarga e avise o cliente!",
                    'parse_mode' => 'html'
                ]);
            }
            unset($pagamentos_pendentes[$uid]);
        }
    }

    // Recebe atualizações
    $url = $api."getUpdates?offset=$offset&timeout=15";
    $resp = @file_get_contents($url);
    if ($resp === false) { sleep(2); continue; }
    
    $dados = json_decode($resp, true);
    if (!isset($dados['result'])) { sleep(1); continue; }
    
    foreach ($dados['result'] as $at) {
        $offset = $at['update_id'] + 1;
        
        // Botões inline
        if (isset($at['callback_query'])) {
            $cb = $at['callback_query'];
            $cid = $cb['message']['chat']['id'];
            $uid = $cb['from']['id'];
            $data = $cb['data'];
            file_get_contents($api."answerCallbackQuery?id=".$cb['id']);
            
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
        
        // Mensagens de texto
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
                $conta = criarContaSSHReal(1);
                if($conta['ok']){
                    enviar(['chat_id' => $cid, 'text' => "✅ TESTE LIBERADO!\n\n".$conta['texto']."\n\n⚠️ 1 teste por dia.", 'parse_mode' => 'html'], true);
                    enviar(['chat_id' => $admin_id, 'text' => "🎁 Novo teste — $uid | {$conta['usuario']}"]);
                } else {
                    enviar(['chat_id' => $cid, 'text' => '❌ Erro ao criar conta de teste.', 'parse_mode' => 'html'], true);
                }
            } else {
                enviar(['chat_id' => $cid, 'text' => '⏰ Já usou o teste hoje! Compre um plano.', 'reply_markup' => json_encode(teclado([['Comprar SSH'], ['Voltar']]))], true);
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
