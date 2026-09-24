<?php
error_reporting(0);
ini_set('display_errors', 0);
set_time_limit(0);

// ==============================================
// ✅ TUDO PRONTO — NÃO ALTERE NADA!
// ==============================================
$token = '8995379428:AAEdxzxUPguxuX51HNjUQ8c65HkjPzV4MZY';
$admin_id = 7761133138;
$mp_token = 'APP_USR-7527190269570273-090920-8e00f0eee8a23cb2fdd7f7d8db4a4dbf-226024458';

$api = "https://api.telegram.org/bot$token/";
$api_mp = "https://api.mercadopago.com/v1/payments";
$offset = 0;
$sessao = [];
$pagamentos_pendentes = [];
$testes_feitos = [];
$mensagem_para_apagar = [];

$planos = [
    1 => ['dias' => 1,  'valor' => 1.00,  'nome' => '1 Dia — R$ 1,00'],
    2 => ['dias' => 5,  'valor' => 4.00,  'nome' => '5 Dias — R$ 4,00'],
    3 => ['dias' => 10, 'valor' => 10.00, 'nome' => '10 Dias — R$ 10,00'],
    4 => ['dias' => 15, 'valor' => 14.00, 'nome' => '15 Dias — R$ 14,00'],
    5 => ['dias' => 30, 'valor' => 20.00, 'nome' => '30 Dias — R$ 20,00'],
];

$operadoras = [
    'vivo'  => ['nome' => '📱 Vivo',  'valores' => [10, 20, 30, 50, 100]],
    'claro' => ['nome' => '📱 Claro', 'valores' => [15, 25, 35, 50, 75]],
    'tim'   => ['nome' => '📱 Tim',   'valores' => [10, 20, 40, 60, 80]],
    'oi'    => ['nome' => '📱 Oi',    'valores' => [15, 30, 50, 70, 100]],
];

// ==============================================
// ✅ CRIA USUÁRIO SSHPlus — JÁ PRONTO!
// ==============================================
function criarUsuario($login, $senha, $dias) {
    $expira = date('d/m/Y', strtotime("+$dias days"));
    $cmd = "cd /root && bash <(wget -qO- https://raw.githubusercontent.com/ProverbioX/sshplus/main/criar.sh) {$login} {$senha} {$dias} 2>&1";
    $saida = shell_exec($cmd);
    $ok = (stripos((string)$saida, 'erro') === false);
    return [
        'ok' => $ok,
        'login' => $login,
        'senha' => $senha,
        'expira' => $expira
    ];
}

function limparMensagensAnteriores($cid) {
    global $api, $mensagem_para_apagar;
    if (!empty($mensagem_para_apagar[$cid])) {
        foreach ($mensagem_para_apagar[$cid] as $mid) {
            $ch = curl_init($api."deleteMessage");
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['chat_id' => $cid, 'message_id' => $mid]));
            curl_setopt($ch, CURLOPT_TIMEOUT, 1);
            curl_exec($ch);
            curl_close($ch);
        }
    }
    $mensagem_para_apagar[$cid] = [];
}

function enviar($dados, $marcar=false) {
    global $api, $mensagem_para_apagar;
    $ch = curl_init($api."sendMessage");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($dados));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resp = curl_exec($ch);
    curl_close($ch);
    if ($marcar && $resp) {
        $r = json_decode($resp, true);
        if (isset($r['result']['message_id'])) $mensagem_para_apagar[$dados['chat_id']][] = $r['result']['message_id'];
    }
}

function teclado($botoes) {
    return ['keyboard' => $botoes, 'resize_keyboard' => true];
}

function podeTestar($uid) {
    global $testes_feitos;
    $hoje = date('Y-m-d');
    if (isset($testes_feitos[$uid]) && $testes_feitos[$uid]===$hoje) return false;
    $testes_feitos[$uid] = $hoje;
    return true;
}

function gerarCredenciais() {
    $login = 'u'.substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 8);
    $senha = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%&'), 0, 10);
    return [$login, $senha];
}

function gerarPix($valor, $desc, $mp_token) {
    global $api_mp;
    $dados = ['transaction_amount' => (float)$valor, 'description' => $desc, 'payment_method_id' => 'pix', 'payer' => ['email' => 'cliente@exemplo.com']];
    $ch = curl_init("$api_mp?access_token=$mp_token");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dados));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $resp = json_decode(curl_exec($ch), true);
    curl_close($ch);
    if (isset($resp['point_of_interaction']['transaction_data']['qr_code']) && isset($resp['id'])) {
        return ['ok' => true, 'pix' => $resp['point_of_interaction']['transaction_data']['qr_code'], 'id' => $resp['id']];
    }
    return ['ok' => false, 'erro' => $resp['message'] ?? 'Erro desconhecido'];
}

echo "✅ BOT PRONTO — TUDO FIXO!\n";

while (true) {
    foreach ($pagamentos_pendentes as $uid => $pg) {
        if (time() - $pg['tempo'] > 900) { unset($pagamentos_pendentes[$uid]); continue; }
        $ch = curl_init($api_mp."/".$pg['mp_id']."?access_token=$mp_token");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        $resp = json_decode(curl_exec($ch), true);
        curl_close($ch);
        
        if (isset($resp['status']) && $resp['status'] === 'approved') {
            if ($pg['tipo'] === 'ssh') {
                list($login, $senha) = gerarCredenciais();
                $conta = criarUsuario($login, $senha, $pg['dias']);
                
                if ($conta['ok']) {
                    $msg = "✅ <b>PAGAMENTO CONFIRMADO!</b>\n\n🔐 <b>SEUS DADOS DE ACESSO</b>\n\n👤 Login: <code>{$conta['login']}</code>\n🔑 Senha: <code>{$conta['senha']}</code>\n📅 Válido até: {$conta['expira']}\n\n🌐 Use o IP desta VPS!";
                    enviar(['chat_id' => $uid, 'text' => $msg, 'parse_mode' => 'html'], true);
                    enviar(['chat_id' => $GLOBALS['admin_id'], 'text' => "💰 PAGO — SSH\n👤 $uid | {$pg['dias']} dias\nLogin: $login\nSenha: $senha\nValor: R$ ".number_format($pg['valor'],2,',','')]);
                } else {
                    enviar(['chat_id' => $uid, 'text' => "✅ PAGO! Erro ao criar conta — avise o administrador.", 'parse_mode' => 'html'], true);
                    enviar(['chat_id' => $GLOBALS['admin_id'], 'text' => "⚠️ ERRO — Não criou conta para $uid"]);
                }
            } else {
                enviar(['chat_id' => $uid, 'text' => "✅ <b>PAGAMENTO CONFIRMADO!</b>\n📱 Recarga em processamento!\nPode demorar até 8h.", 'parse_mode' => 'html'], true);
                enviar(['chat_id' => $GLOBALS['admin_id'], 'text' => "💰 PAGO — RECARGA\n👤 $uid\n📱 {$pg['numero']} | {$pg['nome_op']}\nValor: R$ ".number_format($pg['valor'],2,',','')], true);
            }
            unset($pagamentos_pendentes[$uid]);
        }
    }

    $resp = @file_get_contents($api."getUpdates?offset=$offset&timeout=15");
    if ($resp === false) { sleep(2); continue; }
    $dados = json_decode($resp, true);
    if (!isset($dados['result'])) { sleep(1); continue; }
    
    foreach ($dados['result'] as $at) {
        $offset = $at['update_id'] + 1;
        
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
                    $pagamentos_pendentes[$uid] = ['mp_id' => $pix['id'], 'tipo' => 'ssh', 'dias' => $pl['dias'], 'valor' => $pl['valor'], 'tempo' => time()];
                    enviar(['chat_id' => $cid, 'text' => "💳 <b>PAGAMENTO VIA PIX</b>\n\n{$pl['nome']}\nValor: R$ ".number_format($pl['valor'],2,',','')."\n\nCopie e cole:\n<pre>{$pix['pix']}</pre>", 'parse_mode' => 'html'], true);
                } else {
                    enviar(['chat_id' => $cid, 'text' => "❌ Erro ao gerar PIX: ".$pix['erro'], 'parse_mode' => 'html'], true);
                }
                continue;
            }
            if (strpos($data, 'op_') === 0) {
                $op = substr($data, 3);
                $sessao[$cid]['op'] = $op;
                $btns = [];
                foreach ($operadoras[$op]['valores'] as $v) {
                    $btns[] = [['text' => "R$ $v,00", 'callback_data' => "val_{$op}_$v"]];
                }
                enviar(['chat_id' => $cid, 'text' => "💰 Valores — {$operadoras[$op]['nome']}:", 'reply_markup' => json_encode(['inline_keyboard' => $btns])], true);
                continue;
            }
            if (strpos($data, 'val_') === 0) {
                $p = explode('_', $data);
                list(, $op, $valor) = $p;
                $sessao[$cid] = ['op' => $op, 'valor' => $valor, 'etapa' => 'numero'];
                enviar(['chat_id' => $cid, 'text' => "📱 Digite o número com DDD:\nEx: 11999998888"], true);
                continue;
            }
            continue;
        }
        
        if (!isset($at['message'])) continue;
        $msg = $at['message'];
        $cid = $msg['chat']['id'];
        $uid = $msg['from']['id'];
        $txt = trim($msg['text'] ?? '');
        
        if (isset($sessao[$cid]['etapa']) && $sessao[$cid]['etapa'] === 'numero') {
            limparMensagensAnteriores($cid);
            $num = preg_replace('/\D/', '', $txt);
            if (strlen($num) < 10 || strlen($num) > 11) {
                enviar(['chat_id' => $cid, 'text' => "❌ Número inválido! Ex: 11999998888"], true);
                continue;
            }
            $op = $sessao[$cid]['op'];
            $valor = $sessao[$cid]['valor'];
            $pix = gerarPix($valor, "Recarga {$operadoras[$op]['nome']} — $num", $mp_token);
            if (!$pix['ok']) { 
                enviar(['chat_id' => $cid, 'text' => '❌ Erro ao gerar PIX: '.$pix['erro']], true); 
                unset($sessao[$cid]); 
                continue; 
            }
            $pagamentos_pendentes[$uid] = ['mp_id' => $pix['id'], 'tipo' => 'recarga', 'numero' => $num, 'op' => $op, 'nome_op' => $operadoras[$op]['nome'], 'valor' => $valor, 'tempo' => time()];
            enviar(['chat_id' => $cid, 'text' => "💳 PIX — RECARGA\n\n📱 $num\n📶 {$operadoras[$op]['nome']}\n💰 R$ ".number_format($valor,2,',','')."\n\n<pre>{$pix['pix']}</pre>", 'parse_mode' => 'html'], true);
            unset($sessao[$cid]);
            continue;
        }
        
        if ($txt === '/start' || $txt === 'Voltar') {
            limparMensagensAnteriores($cid);
            enviar(['chat_id' => $cid, 'text' => '👋 Bem-vindo! Escolha:', 'reply_markup' => json_encode(teclado([['Comprar SSH','Teste Grátis'],['Recarga de Celular','Ajuda']]))], false);
        }
        elseif ($txt === 'Comprar SSH') {
            limparMensagensAnteriores($cid);
            $btns = []; foreach ($planos as $id=>$p) $btns[] = [['text'=>$p['nome'],'callback_data'=>"plano_$id"]];
            enviar(['chat_id'=>$cid,'text'=>'🛒 Escolha seu plano:','reply_markup'=>json_encode(['inline_keyboard'=>$btns])],true);
        }
        elseif ($txt === 'Teste Grátis') {
            limparMensagensAnteriores($cid);
            if (podeTestar($uid)) {
                list($login,$senha)=gerarCredenciais();
                $conta=criarUsuario($login,$senha,1);
                if ($conta['ok']) enviar(['chat_id'=>$cid,'text'=>"✅ TESTE LIBERADO!\n\n👤 Login: <code>$login</code>\n🔑 Senha: <code>$senha</code>\n📅 Válido até: {$conta['expira']}",'parse_mode'=>'html'],true);
                else enviar(['chat_id'=>$cid,'text'=>'✅ Teste solicitado, verifique o painel']);
            } else enviar(['chat_id'=>$cid,'text'=>'⏰ Já usou o teste hoje!'],true);
        }
        elseif ($txt === 'Recarga de Celular') {
            limparMensagensAnteriores($cid);
            $btns=[];foreach($operadoras as $k=>$o) $btns[]=[['text'=>$o['nome'],'callback_data'=>"op_$k"]];
            enviar(['chat_id'=>$cid,'text'=>'📱 Escolha a operadora:','reply_markup'=>json_encode(['inline_keyboard'=>$btns])],true);
        }
        elseif ($txt === 'Ajuda') {
            limparMensagensAnteriores($cid);
            enviar(['chat_id'=>$cid,'text'=>'ℹ️ <b>AJUDA</b>\n\n🛒 Comprar → Pagar → Receber dados\n📱 Recarga → Pagar → Processamos\n🎁 Teste → 1 por dia','parse_mode'=>'html'],true);
        }
    }
    usleep(500000);
}
