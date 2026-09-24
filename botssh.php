<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0);

$token = '8995379428:AAEdxzxUPguxuX51HNjUQ8c65HkjPzV4MZY';
$admin_id = 7761133138;
$mp_token = 'APP_USR-7527190269570273-090920-8e00f0eee8a23cb2fdd7f7d8db4a4dbf-226024458';

$api = "https://api.telegram.org/bot$token/";
$api_mp = "https://api.mercadopago.com/v1/payments";
$offset = 0;
$sessao = [];
$pagamentos_pendentes = [];
$testes_feitos = [];

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

function comeca_com($texto, $inicio) {
    return substr($texto, 0, strlen($inicio)) === $inicio;
}

function enviar($dados) {
    global $api;
    $ch = curl_init($api."sendMessage");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($dados));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_exec($ch);
    curl_close($ch);
}

function criarUsuario($login, $senha, $dias) {
    $expira = date('d/m/Y', strtotime("+$dias days"));
    $cmd = "cd /root && bash <(wget -qO- https://raw.githubusercontent.com/ProverbioX/sshplus/main/criar.sh) {$login} {$senha} {$dias} 2>/dev/null";
    $saida = shell_exec($cmd);
    return [
        'ok' => (stripos((string)$saida, 'erro') === false),
        'login' => $login,
        'senha' => $senha,
        'expira' => $expira
    ];
}

function gerarCredenciais() {
    $l = 'u'.substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'),0,8);
    $s = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'),0,10);
    return [$l,$s];
}

function gerarPix($valor, $desc) {
    global $api_mp, $mp_token;
    $dados = json_encode([
        'transaction_amount' => (float)$valor,
        'description' => $desc,
        'payment_method_id' => 'pix',
        'payer' => ['email' => 'cliente@exemplo.com']
    ]);
    $ch = curl_init("$api_mp?access_token=$mp_token");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $dados);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $resposta = curl_exec($ch);
    curl_close($ch);
    
    $r = json_decode($resposta, true);
    
    // ✅ ACHA O PIX DE TODAS AS FORMAS POSSÍVEIS
    $pix_copia = '';
    if (!empty($r['point_of_interaction']['transaction_data']['qr_code'])) {
        $pix_copia = $r['point_of_interaction']['transaction_data']['qr_code'];
    } elseif (!empty($r['qr_code'])) {
        $pix_copia = $r['qr_code'];
    } elseif (!empty($r['qr_code_base64'])) {
        $pix_copia = $r['qr_code_base64'];
    } elseif (!empty($r['ticket_url'])) {
        $pix_copia = $r['ticket_url'];
    }
    
    if ($pix_copia && isset($r['id'])) {
        return ['ok'=>true, 'pix'=>$pix_copia, 'id'=>$r['id']];
    }
    return ['ok'=>false, 'erro'=>$r['message']??'Não conseguiu extrair o PIX'];
}

echo "✅ BOT LIGADO!\n";

while (true) {
    foreach ($pagamentos_pendentes as $uid=>$pg) {
        if (time()-$pg['tempo']>900) { unset($pagamentos_pendentes[$uid]); continue; }
        $ch = curl_init("$api_mp/{$pg['mp_id']}?access_token=$mp_token");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        $r = json_decode(curl_exec($ch), true);
        curl_close($ch);
        
        if (($r['status']??'')==='approved') {
            if ($pg['tipo']==='ssh') {
                list($login,$senha)=gerarCredenciais();
                $conta=criarUsuario($login,$senha,$pg['dias']);
                enviar(['chat_id'=>$uid, 'text'=>"✅ <b>PAGAMENTO CONFIRMADO!</b>\n\n👤 Login: <code>$login</code>\n🔑 Senha: <code>$senha</code>\n📅 Válido até: {$conta['expira']}", 'parse_mode'=>'html']);
                enviar(['chat_id'=>$admin_id, 'text'=>"💰 VENDA\nUsuário: $uid\nPlano: {$pg['dias']} dias\nValor: R$".number_format($pg['valor'],2,',','')]);
            } else {
                enviar(['chat_id'=>$uid, 'text'=>"✅ <b>PAGAMENTO CONFIRMADO!</b>\n📱 Recarga em processamento — pode demorar até 8h!", 'parse_mode'=>'html']);
                enviar(['chat_id'=>$admin_id, 'text'=>"💰 RECARGA\nUsuário: $uid\n📱 {$pg['numero']} — {$pg['nome_op']}\nValor: R$".number_format($pg['valor'],2,',','')]);
            }
            unset($pagamentos_pendentes[$uid]);
        }
    }

    $r = @file_get_contents($api."getUpdates?offset=$offset&timeout=10");
    if (!$r) { usleep(500000); continue; }
    $d = json_decode($r, true);
    if (!isset($d['result'])) continue;

    foreach ($d['result'] as $at) {
        $offset = $at['update_id']+1;

        if (isset($at['callback_query'])) {
            $cb = $at['callback_query'];
            $cid = $cb['message']['chat']['id'];
            $uid = $cb['from']['id'];
            $data = $cb['data'];
            file_get_contents($api."answerCallbackQuery?id=".$cb['id']);

            if (comeca_com($data, 'plano_')) {
                $pid = (int)substr($data, 6);
                if (!isset($planos[$pid])) continue;
                $pl = $planos[$pid];
                $pix = gerarPix($pl['valor'], "SSH {$pl['dias']} dias");
                if ($pix['ok']) {
                    $pagamentos_pendentes[$uid] = ['mp_id'=>$pix['id'], 'tipo'=>'ssh', 'dias'=>$pl['dias'], 'valor'=>$pl['valor'], 'tempo'=>time()];
                    enviar(['chat_id'=>$cid, 'text'=>"💳 <b>PAGAMENTO VIA PIX</b>\n\n{$pl['nome']}\nValor: R$ ".number_format($pl['valor'],2,',','')."\n\n📋 COPIA E COLA:\n<pre>{$pix['pix']}</pre>", 'parse_mode'=>'html']);
                } else {
                    enviar(['chat_id'=>$cid, 'text'=>"❌ Erro: ".$pix['erro']]);
                }
            }
            elseif (comeca_com($data, 'op_')) {
                $op = substr($data, 3);
                $sessao[$cid]['op'] = $op;
                $btns = [];
                foreach ($operadoras[$op]['valores'] as $v) {
                    $btns[] = [['text'=>"R$ $v,00", 'callback_data'=>"val_{$op}_$v"]];
                }
                enviar(['chat_id'=>$cid, 'text'=>"💰 Valores — {$operadoras[$op]['nome']}:", 'reply_markup'=>json_encode(['inline_keyboard'=>$btns])]);
            }
            elseif (comeca_com($data, 'val_')) {
                $p = explode('_', $data);
                list(, $op, $valor) = $p;
                $sessao[$cid] = ['op'=>$op, 'valor'=>$valor, 'etapa'=>'numero'];
                enviar(['chat_id'=>$cid, 'text'=>"📱 Digite o número com DDD:\nEx: 11999998888"]);
            }
            continue;
        }

        if (!isset($at['message'])) continue;
        $msg = $at['message'];
        $cid = $msg['chat']['id'];
        $uid = $msg['from']['id'];
        $txt = trim($msg['text']??'');

        if (($sessao[$cid]['etapa']??'') === 'numero') {
            $num = preg_replace('/\D/', '', $txt);
            if (strlen($num)<10 || strlen($num)>11) {
                enviar(['chat_id'=>$cid, 'text'=>"❌ Número inválido! Ex: 11999998888"]);
                continue;
            }
            $op = $sessao[$cid]['op'];
            $valor = $sessao[$cid]['valor'];
            $pix = gerarPix($valor, "Recarga $num — {$operadoras[$op]['nome']}");
            if (!$pix['ok']) {
                enviar(['chat_id'=>$cid, 'text'=>"❌ Erro: ".$pix['erro']]);
                unset($sessao[$cid]);
                continue;
            }
            $pagamentos_pendentes[$uid] = ['mp_id'=>$pix['id'], 'tipo'=>'recarga', 'numero'=>$num, 'op'=>$op, 'nome_op'=>$operadoras[$op]['nome'], 'valor'=>$valor, 'tempo'=>time()];
            enviar(['chat_id'=>$cid, 'text'=>"💳 <b>PIX — RECARGA</b>\n\n📱 $num\n📶 {$operadoras[$op]['nome']}\n💰 R$ ".number_format($valor,2,',','')."\n\n📋 COPIA E COLA:\n<pre>{$pix['pix']}</pre>", 'parse_mode'=>'html']);
            unset($sessao[$cid]);
            continue;
        }

        if ($txt === '/start' || $txt === 'Voltar') {
            enviar(['chat_id'=>$cid, 'text'=>'👋 Bem-vindo! Escolha:', 'reply_markup'=>json_encode(['keyboard'=>[['Comprar SSH','Teste Grátis'],['Recarga de Celular','Ajuda']], 'resize_keyboard'=>true])]);
        }
        elseif ($txt === 'Comprar SSH') {
            $btns = [];
            foreach ($planos as $id=>$p) $btns[] = [['text'=>$p['nome'], 'callback_data'=>"plano_$id"]];
            enviar(['chat_id'=>$cid, 'text'=>'🛒 Escolha seu plano:', 'reply_markup'=>json_encode(['inline_keyboard'=>$btns])]);
        }
        elseif ($txt === 'Teste Grátis') {
            $hoje = date('Y-m-d');
            if (($testes_feitos[$uid]??'') !== $hoje) {
                list($login,$senha)=gerarCredenciais();
                $conta=criarUsuario($login,$senha,1);
                $testes_feitos[$uid] = $hoje;
                enviar(['chat_id'=>$cid, 'text'=>"✅ <b>TESTE LIBERADO!</b>\n\n👤 Login: <code>$login</code>\n🔑 Senha: <code>$senha</code>", 'parse_mode'=>'html']);
            } else {
                enviar(['chat_id'=>$cid, 'text'=>'⏰ Já usou o teste hoje! Volta amanhã.']);
            }
        }
        elseif ($txt === 'Recarga de Celular') {
            $btns = [];
            foreach ($operadoras as $k=>$o) $btns[] = [['text'=>$o['nome'], 'callback_data'=>"op_$k"]];
            enviar(['chat_id'=>$cid, 'text'=>'📱 Escolha a operadora:', 'reply_markup'=>json_encode(['inline_keyboard'=>$btns])]);
        }
        elseif ($txt === 'Ajuda') {
            enviar(['chat_id'=>$cid, 'text'=>"ℹ️ <b>AJUDA</b>\n\n🛒 Comprar SSH → Pagar → Receber dados\n📱 Recarga → Pagar → Processamento em até 8h\n🎁 Teste grátis → 1 por dia", 'parse_mode'=>'html']);
        }
    }
    usleep(300000);
}
