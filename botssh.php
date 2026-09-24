<?php
error_reporting(0);
ini_set('display_errors', 0);
set_time_limit(0);

// ==============================================
// DADOS — CONFERE SE ESTÃO CERTOS!
// ==============================================
$token = '8174530969:AAHTMbMf1KEbIU5c-4aCtaiBRDvF4Dn9-Bs';
$admin_id = 7761133138;
$mp_token = 'APP_USR-7527190269570273-090920-8e00f0eee8a23cb2fdd7f7d8db4a4dbf-226024458';

$api = "https://api.telegram.org/bot$token/";
$api_mp = "https://api.mercadopago.com/v1/payments";
$offset = 0;
$sessao = [];
$pagamentos = [];
$testes_feitos = [];

// Planos SSH
$planos = [
    1  => ['dias' => 1,  'valor' => 1.00,  'nome' => '1 Dia — R$ 1,00'],
    2  => ['dias' => 5,  'valor' => 4.00,  'nome' => '5 Dias — R$ 4,00'],
    3  => ['dias' => 10, 'valor' => 10.00, 'nome' => '10 Dias — R$ 10,00'],
    4  => ['dias' => 15, 'valor' => 14.00, 'nome' => '15 Dias — R$ 14,00'],
    5  => ['dias' => 30, 'valor' => 20.00, 'nome' => '30 Dias — R$ 20,00'],
];

// Operadoras de recarga
$operadoras = [
    'vivo'    => ['nome' => '📱 Vivo',    'valores' => [10, 20, 30, 50, 100]],
    'claro'   => ['nome' => '📱 Claro',   'valores' => [15, 25, 35, 50, 75]],
    'tim'     => ['nome' => '📱 Tim',     'valores' => [10, 20, 40, 60, 80]],
    'oi'      => ['nome' => '📱 Oi',      'valores' => [15, 30, 50, 70, 100]],
];

function enviar($dados) {
    global $api;
    $ch = curl_init($api."sendMessage");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($dados));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

function teclado($botoes, $resizer = true) {
    return ['keyboard' => $botoes, 'resize_keyboard' => $resizer];
}

function podeTestar($uid) {
    global $testes_feitos;
    $hoje = date('Y-m-d');
    if (isset($testes_feitos[$uid]) && $testes_feitos[$uid] === $hoje) return false;
    $testes_feitos[$uid] = $hoje;
    return true;
}

echo "✅ BOT INICIADO — Funcionando!\n";

while (true) {
    $url = $api."getUpdates?offset=$offset&timeout=15";
    $resp = @file_get_contents($url);
    if ($resp === false) { sleep(2); continue; }
    
    $dados = json_decode($resp, true);
    if (!isset($dados['result'])) { sleep(1); continue; }
    
    foreach ($dados['result'] as $atualizacao) {
        $offset = $atualizacao['update_id'] + 1;
        
        if (isset($atualizacao['callback_query'])) {
            $cb = $atualizacao['callback_query'];
            $cid = $cb['message']['chat']['id'];
            $uid = $cb['from']['id'];
            $dados_cb = $cb['data'];
            file_get_contents($api."answerCallbackQuery?id=".$cb['id']);
            
            if (strpos($dados_cb, 'plano_') === 0) {
                $pid = (int)substr($dados_cb, 6);
                if (!isset($planos[$pid])) continue;
                $plano = $planos[$pid];
                
                $mp_dados = [
                    'transaction_amount' => $plano['valor'],
                    'description' => "Plano SSH — {$plano['dias']} dias",
                    'payment_method_id' => 'pix',
                    'payer' => ['email' => 'cliente@exemplo.com']
                ];
                $ch = curl_init("$api_mp?access_token=$mp_token");
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($mp_dados));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $mp_resp = json_decode(curl_exec($ch), true);
                curl_close($ch);
                
                if (isset($mp_resp['point_of_interaction']['transaction_data']['qr_code'])) {
                    $pix_copia = $mp_resp['point_of_interaction']['transaction_data']['qr_code'];
                    $pagamentos[$uid] = ['plano' => $pid, 'id' => $mp_resp['id']];
                    
                    enviar([
                        'chat_id' => $cid,
                        'text' => "💳 <b>PAGAMENTO VIA PIX</b>\n\n⏳ Plano: {$plano['nome']}\n💰 Valor: R$ ".number_format($plano['valor'],2,',','')."\n\n📋 Copie e cole no app do banco:\n<pre>$pix_copia</pre>\n\n✅ Após confirmação, envio os dados de acesso!\n⌛ Pode demorar até 8h para processar.",
                        'parse_mode' => 'html'
                    ]);
                } else {
                    enviar(['chat_id' => $cid, 'text' => "❌ Erro ao gerar PIX. Tente novamente."]);
                }
                continue;
            }
            
            if (strpos($dados_cb, 'op_') === 0) {
                $op = substr($dados_cb, 3);
                if (!isset($operadoras[$op])) continue;
                $sessao[$cid]['operadora'] = $op;
                $botoes_valores = [];
                foreach ($operadoras[$op]['valores'] as $v) {
                    $botoes_valores[] = [['text' => "R$ $v,00", 'callback_data' => "val_${op}_$v"]];
                }
                enviar([
                    'chat_id' => $cid,
                    'text' => "💰 Valores disponíveis para {$operadoras[$op]['nome']}:",
                    'reply_markup' => json_encode(['inline_keyboard' => $botoes_valores])
                ]);
                continue;
            }
            
            if (strpos($dados_cb, 'val_') === 0) {
                list(,, $op, $valor) = explode('_', $dados_cb);
                $sessao[$cid]['valor'] = $valor;
                $sessao[$cid]['operadora'] = $op;
                enviar([
                    'chat_id' => $cid,
                    'text' => "📱 Digite o número com DDD:\nExemplo: 11999998888"
                ]);
                $sessao[$cid]['etapa'] = 'numero_recarga';
                continue;
            }
            continue;
        }
        
        if (!isset($atualizacao['message'])) continue;
        $msg = $atualizacao['message'];
        $cid = $msg['chat']['id'];
        $uid = $msg['from']['id'];
        $texto = trim($msg['text'] ?? '');
        
        if (isset($sessao[$cid]['etapa']) && $sessao[$cid]['etapa'] === 'numero_recarga') {
            $numero = preg_replace('/\D/', '', $texto);
            if (strlen($numero) < 10 || strlen($numero) > 11) {
                enviar(['chat_id' => $cid, 'text' => "❌ Número inválido! Digite com DDD:\nExemplo: 11999998888"]);
                continue;
            }
            $op = $sessao[$cid]['operadora'];
            $valor = $sessao[$cid]['valor'];
            
            enviar([
                'chat_id' => $admin_id,
                'text' => "🔔 NOVO PEDIDO DE RECARGA\n\n👤 Usuário: $cid\n📱 Número: $numero\n📶 Operadora: {$operadoras[$op]['nome']}\n💰 Valor: R$ $valor,00\n👉 Faça a recarga manual e avise o cliente!"
            ]);
            
            enviar([
                'chat_id' => $cid,
                'text' => "✅ Pedido recebido!\n\n📱 Número: <code>$numero</code>\n📶 Operadora: {$operadoras[$op]['nome']}\n💰 Valor: R$ $valor,00\n\n⌛ Pode demorar até 8 horas.",
                'parse_mode' => 'html'
            ]);
            unset($sessao[$cid]);
            continue;
        }
        
        if ($texto === '/start' || $texto === 'Voltar') {
            enviar([
                'chat_id' => $cid,
                'text' => "👋 Bem-vindo! Escolha uma opção abaixo:",
                'reply_markup' => json_encode(teclado([
                    ['COMPRAR SSH ✅', 'Teste Grátis'],
                    ['Recarga de Celular', 'Ajuda']
                ]))
            ]);
        }
        elseif ($texto === 'COMPRAR SSH ✅' || $texto === 'Comprar SSH') {
            $botoes = [];
            foreach ($planos as $pid => $pl) {
                $botoes[] = [['text' => $pl['nome'], 'callback_data' => "plano_$pid"]];
            }
            enviar([
                'chat_id' => $cid,
                'text' => "🛒 Escolha seu plano de acesso SSH:",
                'reply_markup' => json_encode(['inline_keyboard' => $botoes])
            ]);
        }
        elseif ($texto === 'Teste Grátis') {
            if (podeTestar($uid)) {
                enviar([
                    'chat_id' => $cid,
                    'text' => "✅ Teste liberado!\n\n🔐 Login: teste_".substr(md5($uid.time()), 0, 6)."\n🔑 Senha: 12345678\n⏳ Válido por 24h\n\n⚠️ Apenas 1 teste por dia."
                ]);
                enviar(['chat_id' => $admin_id, 'text' => "🎁 Novo teste grátis — Usuário: $uid"]);
            } else {
                enviar([
                    'chat_id' => $cid,
                    'text' => "⏰ Já usou seu teste hoje!\nVolte amanhã ou escolha um plano:",
                    'reply_markup' => json_encode(teclado([['COMPRAR SSH ✅'], ['Voltar']]))
                ]);
            }
        }
        elseif ($texto === 'Recarga de Celular') {
            $botoes_op = [];
            foreach ($operadoras as $chave => $op) {
                $botoes_op[] = [['text' => $op['nome'], 'callback_data' => "op_$chave"]];
            }
            enviar([
                'chat_id' => $cid,
                'text' => "📱 Escolha a operadora:",
                'reply_markup' => json_encode(['inline_keyboard' => $botoes_op])
            ]);
        }
        elseif ($texto === 'Ajuda') {
            enviar([
                'chat_id' => $cid,
                'text' => "ℹ️ <b>AJUDA</b>\n\n🛒 Comprar SSH → Escolha → PIX → Receba dados\n🎁 Teste Grátis → 1 por dia, 24h\n📱 Recarga → Preencha → Eu faço manual\n\nDúvidas? Fale com o administrador.",
                'parse_mode' => 'html'
            ]);
        }
    }
    sleep(1);
}
