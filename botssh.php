<?php
error_reporting(0);
ini_set('display_errors', 0);
set_time_limit(0);

// ==============================================
// SEUS DADOS — TOKEN ATUALIZADO ✅
// ==============================================
$token = '8591852336:AAHHK2tuPC0tjJK9G8gcjBk8x2FxylSVQu8';
$admin_id = 7761133138;
$mp_token = 'APP_USR-7527190269570273-090920-8e00f0eee8a23cb2fdd7f7d8db4a4dbf-226024458';

$api = "https://api.telegram.org/bot$token/";
$api_mp = "https://api.mercadopago.com/v1/payments/";
$offset = 0;
$sessao = [];
$pagamentos = [];
$testes_feitos = [];

// Planos de venda
$planos = [
    1 => ['dias' => 1,  'valor' => 1.00,  'nome' => '1 Dia — R$ 1,00'],
    2 => ['dias' => 5,  'valor' => 4.00,  'nome' => '5 Dias — R$ 4,00'],
    3 => ['dias' => 10, 'valor' => 10.00, 'nome' => '10 Dias — R$ 10,00'],
    4 => ['dias' => 15, 'valor' => 14.00, 'nome' => '15 Dias — R$ 14,00'],
    5 => ['dias' => 30, 'valor' => 20.00, 'nome' => '30 Dias — R$ 20,00'],
];

// Recarga de celular
$recargas = [
    'vivo'  => ['nome'=>'📱 VIVO',   'valores'=>[15,25,35,50,100]],
    'claro' => ['nome'=>'📱 CLARO',  'valores'=>[10,20,30,50,80]],
    'tim'   => ['nome'=>'📱 TIM',    'valores'=>[15,25,40,60,90]],
    'oi'    => ['nome'=>'📱 OI',     'valores'=>[10,20,35,50]]
];

// ==============================================
// CRIA CONTA REAL — chama gerarusuario.sh
// ==============================================
function criarContaReal($dias){
    $usuario = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
    $senha = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%&'), 0, 10);
    
    if(file_exists('gerarusuario.sh')){
        @chmod('gerarusuario.sh', 0755);
        exec("./gerarusuario.sh $usuario $senha $dias 1");
    } else {
        exec("userdel -r $usuario 2>/dev/null");
        exec("useradd -M -s /bin/false $usuario");
        exec("echo '$usuario:$senha' | chpasswd");
    }
    
    $expira = date('d/m/Y', strtotime("+$dias days"));
    $ip_servidor = gethostbyname(gethostname());
    
    return [
        'ok' => true,
        'usuario' => $usuario,
        'senha' => $senha,
        'ip' => $ip_servidor,
        'dias' => $dias,
        'expira' => $expira,
        'texto' => "✅ <b>CONTA CRIADA COM SUCESSO!</b>\n\n".
                   "🌐 Servidor: <code>$ip_servidor</code>\n".
                   "👤 Usuário: <code>$usuario</code>\n".
                   "🔑 Senha: <code>$senha</code>\n".
                   "📅 Válido até: <b>$expira</b>\n\n".
                   "✅ Conecte normalmente!"
    ];
}

function podeTestar($uid){
    global $testes_feitos;
    $hoje = date('Y-m-d');
    if(isset($testes_feitos[$uid]) && $testes_feitos[$uid]===$hoje) return false;
    $testes_feitos[$uid] = $hoje;
    return true;
}

function gerarPix($valor, $desc){
    global $mp_token;
    $dados = [
        "transaction_amount" => $valor,
        "description" => $desc,
        "payment_method_id" => "pix",
        "payer" => ["email" => "cliente@bot.com", "first_name" => "Cliente"]
    ];
    $ch = curl_init("https://api.mercadopago.com/v1/payments");
    curl_setopt_array($ch, [
        CURLOPT_POST => 1,
        CURLOPT_POSTFIELDS => json_encode($dados),
        CURLOPT_HTTPHEADER => ["Content-Type: application/json", "Authorization: Bearer $mp_token"],
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_TIMEOUT => 15
    ]);
    $resp = json_decode(curl_exec($ch), true);
    curl_close($ch);
    
    if(isset($resp['point_of_interaction']['transaction_data']['qr_code']) && isset($resp['id'])){
        return ['ok'=>true, 'pix'=>$resp['point_of_interaction']['transaction_data']['qr_code'], 'id'=>$resp['id']];
    }
    return ['ok'=>false, 'erro'=>$resp['message']??'Erro ao gerar PIX'];
}

function verificarPagamento($id_pag){
    global $api_mp, $mp_token;
    $ch = curl_init("$api_mp$id_pag");
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $mp_token"],
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_TIMEOUT => 10
    ]);
    $d = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return ($d['status']??'') === 'approved';
}

function enviar($d){
    global $api;
    $ch = curl_init($api.'sendMessage');
    curl_setopt_array($ch, [CURLOPT_POST=>1, CURLOPT_POSTFIELDS=>http_build_query($d), CURLOPT_RETURNTRANSFER=>1, CURLOPT_SSL_VERIFYPEER=>0, CURLOPT_TIMEOUT=>10]);
    curl_exec($ch); curl_close($ch);
}

function editar($d){
    global $api;
    $ch = curl_init($api.'editMessageText');
    curl_setopt_array($ch, [CURLOPT_POST=>1, CURLOPT_POSTFIELDS=>http_build_query($d), CURLOPT_RETURNTRANSFER=>1, CURLOPT_SSL_VERIFYPEER=>0, CURLOPT_TIMEOUT=>10]);
    curl_exec($ch); curl_close($ch);
}

echo "✅ BOT INICIADO — TOKEN NOVO!\n";

while(true){
    foreach($pagamentos as $id_pag => $pedido){
        if(verificarPagamento($id_pag)){
            $cid = $pedido['cid'];
            if($pedido['tipo'] === 'ssh'){
                $conta = criarContaReal($pedido['dias']);
                if($conta['ok']){
                    enviar(['chat_id'=>$cid, 'text'=>$conta['texto'], 'parse_mode'=>'html']);
                    enviar(['chat_id'=>$GLOBALS['admin_id'], 'text'=>"💰 VENDA CONFIRMADA!\n👤 Cliente: $cid\n📋 Usuário: {$conta['usuario']}\n⏱️ Dias: {$pedido['dias']}\n💵 R$ ".number_format($pedido['valor'],2,',','')]);
                } else {
                    enviar(['chat_id'=>$cid, 'text'=>'✅ Pago! Erro ao criar conta — fale com suporte.', 'parse_mode'=>'html']);
                }
            } else {
                enviar(['chat_id'=>$cid, 'text'=>"✅ <b>PAGAMENTO CONFIRMADO!</b>\n📱 Recarga em processamento — até 8h.", 'parse_mode'=>'html']);
                enviar(['chat_id'=>$GLOBALS['admin_id'], 'text'=>"💰 RECARGA PAGA!\n👤 $cid\n📱 {$pedido['numero']}\n📶 {$pedido['nome_op']}\n💰 R$ ".number_format($pedido['valor'],2,',','')]);
            }
            unset($pagamentos[$id_pag]);
        }
    }

    $ch = curl_init($api."getUpdates?offset=$offset&timeout=5");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_SSL_VERIFYPEER=>0, CURLOPT_TIMEOUT=>10]);
    $resp = curl_exec($ch); curl_close($ch);
    if(!$resp){ usleep(100000); continue; }
    
    $dados = json_decode($resp, true);
    if(!isset($dados['result'])){ usleep(100000); continue; }
    
    foreach($dados['result'] as $u){
        $offset = $u['update_id'] + 1;
        $cid = $txt = $cb = $mid = '';
        
        if(isset($u['message'])){
            $cid = $u['message']['chat']['id'];
            $txt = trim($u['message']['text']??'');
            $mid = $u['message']['message_id'];
        }
        if(isset($u['callback_query'])){
            $cb = $u['callback_query']['data'];
            $cid = $u['callback_query']['message']['chat']['id'];
            $mid = $u['callback_query']['message']['message_id'];
            @file_get_contents($api."answerCallbackQuery?callback_query_id=".$u['callback_query']['id']);
        }
        if(!$cid) continue;

        if($txt === '/start' || $cb === 'voltar'){
            $kb = ['inline_keyboard' => [
                [['text'=>'🎁 TESTE GRÁTIS','callback_data'=>'teste']],
                [['text'=>'🛒 COMPRAR SSH','callback_data'=>'comprar']],
                [['text'=>'📱 RECARGA DE CELULAR','callback_data'=>'recarga_lista']]
            ]];
            $msg = ['chat_id'=>$cid, 'text'=>"<b>👋 BEM-VINDO!</b>\nEscolha uma opção abaixo 👇", 'parse_mode'=>'html', 'reply_markup'=>json_encode($kb)];
            $cb ? editar(array_merge($msg, ['message_id'=>$mid])) : enviar($msg);
            continue;
        }

        if($cb === 'teste'){
            if(podeTestar($cid)){
                $conta = criarContaReal(1);
                enviar(['chat_id'=>$cid, 'text'=>"✅ <b>TESTE LIBERADO!</b>\n\n".$conta['texto']."\n\n⚠️ 1 teste por dia.", 'parse_mode'=>'html']);
                enviar(['chat_id'=>$admin_id, 'text'=>"🎁 NOVO TESTE — $cid | {$conta['usuario']}"]);
            } else {
                enviar(['chat_id'=>$cid, 'text'=>'⏰ Já usou o teste hoje! Compre um plano.', 'parse_mode'=>'html']);
            }
            continue;
        }

        if($cb === 'comprar'){
            $kb = ['inline_keyboard' => []];
            foreach($planos as $id=>$p) $kb['inline_keyboard'][] = [['text'=>$p['nome'], 'callback_data'=>"plano_$id"]];
            $kb['inline_keyboard'][] = [['text'=>'◀️ Voltar','callback_data'=>'voltar']];
            editar(['chat_id'=>$cid,'message_id'=>$mid,'text'=>'🛒 Escolha seu plano:','parse_mode'=>'html','reply_markup'=>json_encode($kb)]);
            continue;
        }

        if(strpos($cb, 'plano_') === 0){
            $id = (int)substr($cb, 6);
            if(!isset($planos[$id])) continue;
            $p = $planos[$id];
            $pix = gerarPix($p['valor'], "Plano SSH {$p['dias']} dias");
            if($pix['ok']){
                $pagamentos[$pix['id']] = ['cid'=>$cid, 'tipo'=>'ssh', 'dias'=>$p['dias'], 'valor'=>$p['valor']];
                editar(['chat_id'=>$cid,'message_id'=>$mid,'text'=>"💳 <b>PAGAMENTO VIA PIX</b>\n\n⏳ {$p['nome']}\n💰 Valor: R$ ".number_format($p['valor'],2,',','')."\n\n📋 Copie e cole:\n<pre>{$pix['pix']}</pre>\n✅ Após pagar, conta é criada automática!", 'parse_mode'=>'html']);
            } else {
                editar(['chat_id'=>$cid,'message_id'=>$mid,'text'=>'❌ Erro: '.($pix['erro']??'Tente novamente'), 'parse_mode'=>'html']);
            }
            continue;
        }

        if($cb === 'recarga_lista'){
            $kb = ['inline_keyboard' => []];
            foreach($recargas as $chave=>$op) $kb['inline_keyboard'][] = [['text'=>$op['nome'],'callback_data'=>"op_$chave"]];
            $kb['inline_keyboard'][] = [['text'=>'◀️ Voltar','callback_data'=>'voltar']];
            editar(['chat_id'=>$cid,'message_id'=>$mid,'text'=>'📱 Escolha a operadora:','parse_mode'=>'html','reply_markup'=>json_encode($kb)]);
            continue;
        }

        if(strpos($cb, 'op_') === 0){
            $op = substr($cb, 3);
            if(!isset($recargas[$op])) continue;
            $sessao[$cid]['op'] = $op;
            $kb = ['inline_keyboard' => []];
            foreach($recargas[$op]['valores'] as $v) $kb['inline_keyboard'][] = [['text'=>"R$ ".number_format($v,2,',',''),'callback_data'=>"val_{$op}_$v"]];
            $kb['inline_keyboard'][] = [['text'=>'◀️ Voltar','callback_data'=>'recarga_lista']];
            editar(['chat_id'=>$cid,'message_id'=>$mid,'text'=>$recargas[$op]['nome']."\nEscolha o valor:",'parse_mode'=>'html','reply_markup'=>json_encode($kb)]);
            continue;
        }

        if(strpos($cb, 'val_') === 0){
            $partes = explode('_', $cb);
            if(count($partes)!==3) continue;
            list(,$op,$v) = $partes;
            if(!isset($recargas[$op])) continue;
            $sessao[$cid]['op'] = $op;
            $sessao[$cid]['valor'] = $v;
            $sessao[$cid]['etapa'] = 'pedir_numero';
            editar(['chat_id'=>$cid,'message_id'=>$mid,'text'=>"✅ Valor: R$ ".number_format($v,2,',','')."\nDigite o número com DDD:",'parse_mode'=>'html']);
            continue;
        }

        if(($sessao[$cid]['etapa']??'') === 'pedir_numero' && $txt){
            $num = preg_replace('/\D/', '', $txt);
            if(strlen($num)<10 || strlen($num)>11){
                enviar(['chat_id'=>$cid,'text'=>'❌ Número inválido! Com DDD, ex: 11999998888','parse_mode'=>'html']);
                continue;
            }
            $op = $sessao[$cid]['op'];
            $v = $sessao[$cid]['valor'];
            $pix = gerarPix($v, "Recarga {$recargas[$op]['nome']} - $num");
            if(!$pix['ok']){
                enviar(['chat_id'=>$cid,'text'=>'❌ Erro ao gerar PIX','parse_mode'=>'html']);
                unset($sessao[$cid]);
                continue;
            }
            $pagamentos[$pix['id']] = ['cid'=>$cid, 'tipo'=>'recarga', 'numero'=>$num, 'op'=>$op, 'nome_op'=>$recargas[$op]['nome'], 'valor'=>$v];
            enviar(['chat_id'=>$cid,'text'=>"💳 <b>PIX — RECARGA</b>\n📱 <code>$num</code>\n📶 {$recargas[$op]['nome']}\n💰 R$ ".number_format($v,2,',','')."\n\n📋 Copie e cole:\n<pre>{$pix['pix']}</pre>\n⌛ Até 8h.",'parse_mode'=>'html']);
            unset($sessao[$cid]);
        }
    }
    usleep(100000);
}
