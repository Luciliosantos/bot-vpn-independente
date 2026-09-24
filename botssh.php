<?php
error_reporting(0);
ini_set('display_errors', 0);
set_time_limit(0);

// ================= DADOS =================
$token = '8999330752:AAF-JcIr6AwhK7uPkrOCtFUPUaN294SKDBk';
$admin_id = 7761133138;
$mp_token = 'APP_USR-7527190269570273-090920-8e00f0eee8a23cb2fdd7f7d8db4a4dbf-226024458';
$api = "https://api.telegram.org/bot$token/";
$api_mp = "https://api.mercadopago.com/v1/payments/";
$offset = 0;
$sessao = [];
$pagamentos = [];
$testes_feitos = [];

// PLANOS SSH
$planos = [
    1 => ['dias' => 1, 'valor' => 1.00, 'nome' => '1 Dia - R$ 1,00'],
    2 => ['dias' => 5, 'valor' => 4.00, 'nome' => '5 Dias - R$ 4,00'],
    3 => ['dias' => 10, 'valor' => 10.00, 'nome' => '10 Dias - R$ 10,00'],
    4 => ['dias' => 15, 'valor' => 14.00, 'nome' => '15 Dias - R$ 14,00'],
    5 => ['dias' => 30, 'valor' => 20.00, 'nome' => '30 Dias - R$ 20,00'],
];

// OPERADORAS RECARGA
$operadoras = [
    'vivo' => ['nome' => '📱 Vivo', 'valores' => [10,20,30,50,100]],
    'claro' => ['nome' => '📱 Claro', 'valores' => [15,25,35,50,75]],
    'tim'  => ['nome' => '📱 Tim',  'valores' => [10,20,40,60,80]],
    'oi'   => ['nome' => '📱 Oi',   'valores' => [15,30,50,70,100]],
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

function podeTestar($uid) {
    global $testes_feitos;
    $hoje = date('Y-m-d');
    if (isset($testes_feitos[$uid]) && $testes_feitos[$uid] === $hoje) return false;
    $testes_feitos[$uid] = $hoje;
    return true;
}

function gerarCredenciais() {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $user = substr(str_shuffle($chars),0,8);
    $pass = substr(str_shuffle($chars),0,12);
    return [$user, $pass];
}

echo "✅ BOT INICIADO!\n";

while (true) {
    $url = $api."getUpdates?offset=$offset&timeout=15";
    $resp = @file_get_contents($url);
    if ($resp === false) { sleep(2); continue; }
    
    $dados = json_decode($resp, true);
    if (!isset($dados['result'])) { sleep(1); continue; }

    foreach ($dados['result'] as $atualizacao) {
        $offset = $atualizacao['update_id'] + 1;

        // === CALLBACK (botões) ===
        if (isset($atualizacao['callback_query'])) {
            $cb = $atualizacao['callback_query'];
            $cid = $cb['message']['chat']['id'];
            $uid = $cb['from']['id'];
            $dados_cb = $cb['data'];
            file_get_contents($api."answerCallbackQuery?id=".$cb['id']);

            // PLANO SSH
            if (strpos($dados_cb, 'plano_') === 0) {
                $pid = (int)substr($dados_cb, 6);
                if (!isset($planos[$pid])) continue;
                $plano = $planos[$pid];

                $mp_dados = [
                    'transaction_amount' => $plano['valor'],
                    'description' => "Plano SSH - {$plano['dias']} dias",
                    'payment_method_id' => 'pix',
                    'payer' => ['email' => 'cliente@exemplo.com']
                ];

                $ch = curl_init($api_mp."?access_token=$mp_token");
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($mp_dados));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $mp_resp = json_decode(curl_exec($ch), true);
                curl_close($ch);

                if (isset($mp_resp['point_of_interaction']['transaction_data']['qr_code'])) {
                    $pix_copia = $mp_resp['point_of_interaction']['transaction_data']['qr_code'];
                    $pagamentos[$mp_resp['id']] = ['cid'=>$cid, 'plano'=>$plano, 'verificado'=>false];
                    
                    enviar([
                        'chat_id' => $cid,
                        'text' => "💳 <b>PAGAMENTO VIA PIX</b>\n\n⏳ Plano: {$plano['nome']}\n💰 Valor: R$ ".number_format($plano['valor'],2,',','')."\n\n📋 Copie e cole no app do banco:\n<pre>$pix_copia</pre>\n✅ Após pagamento, seus dados serão enviados automaticamente!\nPode demorar até 8h.",
                        'parse_mode' => 'html'
                    ]);
                } else {
                    enviar(['chat_id'=>$cid, 'text' => "❌ Erro ao gerar PIX. Tente novamente."]);
                }
                continue;
            }

            // OPÇÃO REDE
            if (strpos($dados_cb, 'op_') === 0) {
                $op = substr($dados_cb, 3);
                if (!isset($operadoras[$op])) continue;
                $sessao[$cid]['operadora'] = $op;
                $botoes_valores = [];
                foreach ($operadoras[$op]['valores'] as $v) {
                    $botoes_valores[] = [['text' => "R$ $v,00", 'callback_data' => "val_{$op}_$v"]];
                }
                enviar([
                    'chat_id' => $cid,
                    'text' => "💰 Valores para {$operadoras[$op]['nome']}:",
                    'reply_markup' => json_encode(['inline_keyboard' => $botoes_valores])
                ]);
                continue;
            }

            // VALOR REDE
            if (strpos($dados_cb, 'val_') === 0) {
                list(,,$op,$valor) = explode('_', $dados_cb);
                $sessao[$cid]['operadora'] = $op;
                $sessao[$cid]['valor'] = $valor;
                $sessao[$cid]['etapa'] = 'numero_recarga';
                enviar([
                    'chat_id' => $cid,
                    'text' => "📱 Digite o número com DDD:\nExemplo: 11999998888"
                ]);
                continue;
            }
        }

        // === MENSAGENS ===
        if (!isset($atualizacao['message'])) continue;
        $msg = $atualizacao['message'];
        $cid = $msg['chat']['id'];
        $uid = $msg['from']['id'];
        $texto = trim($msg['text'] ?? '');

        // ETAPA: digitar número
        if (isset($sessao[$cid]['etapa']) && $sessao[$cid]['etapa'] === 'numero_recarga') {
            $numero = preg_replace('/\D/', '', $texto);
            if (strlen($numero) < 10 || strlen($numero) > 11) {
                enviar(['chat_id'=>$cid, 'text' => "❌ Número inválido! Digite com DDD:\nExemplo: 11999998888"]);
                continue;
            }
            $op = $sessao[$cid]['operadora'];
            $valor = $sessao[$cid]['valor'];

            // AVISA ADMIN
            enviar([
                'chat_id' => $admin_id,
                'text' => "🔔 NOVO PEDIDO DE RECARGA\n\nUsuário: $cid\nNúmero: <code>$numero</code>\nOperadora: {$operadoras[$op]['nome']}\nValor: R$ $valor,00\n👉 Faça a recarga e avise o cliente!",
                'parse_mode' => 'html'
            ]);

            // CONFIRMA CLIENTE
            enviar([
                'chat_id' => $cid,
                'text' => "✅ Pedido recebido!\n\n📱 Número: <code>$numero</code>\n📡 Operadora: {$operadoras[$op]['nome']}\n💰 Valor: R$ $valor,00\n\n⏳ Pode demorar até 8 horas para ser processado.",
                'parse_mode' => 'html'
            ]);
            unset($sessao[$cid]);
            continue;
        }

        // COMANDO /start
        if ($texto === '/start' || $texto === 'Voltar') {
            $botoes = [
                [['text' => '🛒 Comprar SSH', 'callback_data' => 'comprar_ssh']],
                [['text' => '🎁 Teste Grátis', 'callback_data' => 'teste_gratis']],
                [['text' => '📱 Recarga', 'callback_data' => 'recarga']],
                [['text' => 'ℹ️ Ajuda', 'callback_data' => 'ajuda']],
            ];
            enviar([
                'chat_id' => $cid,
                'text' => "👋 <b>Bem-vindo!</b>\nEscolha uma opção abaixo 👇",
                'parse_mode' => 'html',
                'reply_markup' => json_encode(['inline_keyboard' => $botoes])
            ]);
            continue;
        }

        // COMPRAR SSH
        if ($texto === 'Comprar SSH' || $texto === 'comprar_ssh' || $texto === '🛒 Comprar SSH') {
            $botoes_planos = [];
            foreach ($planos as $pid => $plano) {
                $botoes_planos[] = [['text' => $plano['nome'], 'callback_data' => "plano_$pid"]];
            }
            enviar([
                'chat_id' => $cid,
                'text' => "🛒 Escolha seu plano:",
                'reply_markup' => json_encode(['inline_keyboard' => $botoes_planos])
            ]);
            continue;
        }

        // TESTE GRÁTIS
        if ($texto === 'Teste Grátis' || $texto === 'teste_gratis' || $texto === '🎁 Teste Grátis') {
            if (!podeTestar($uid)) {
                enviar(['chat_id' => $cid, 'text' => "❌ Você já usou seu teste hoje! Volte amanhã."]);
                continue;
            }
            list($usuario, $senha) = gerarCredenciais();
            enviar([
                'chat_id' => $cid,
                'text' => "✅ Teste liberado!\n\n👤 Usuário: <code>$usuario</code>\n🔑 Senha: <code>$senha</code>\n⏰ Válido por 24h\n\nVolte sempre!",
                'parse_mode' => 'html'
            ]);
            enviar([
                'chat_id' => $admin_id,
                'text' => "🎁 TESTE GRÁTIS\nUsuário: $cid\nConta: $usuario / $senha"
            ]);
            continue;
        }

        // RECARGA
        if ($texto === 'Recarga' || $texto === 'recarga' || $texto === '📱 Recarga') {
            $botoes_op = [];
            foreach ($operadoras as $chave => $op) {
                $botoes_op[] = [['text' => $op['nome'], 'callback_data' => "op_$chave"]];
            }
            enviar([
                'chat_id' => $cid,
                'text' => "📱 Escolha a operadora:",
                'reply_markup' => json_encode(['inline_keyboard' => $botoes_op])
            ]);
            continue;
        }

        // AJUDA
        if ($texto === 'Ajuda' || $texto === 'ajuda' || $texto === 'ℹ️ Ajuda') {
            enviar([
                'chat_id' => $cid,
                'text' => "ℹ️ <b>AJUDA</b>\n\n🛒 Comprar SSH → Escolha o plano → PIX → Receba dados\n🎁 Teste Grátis → 1 por dia, válido 24h\n📱 Recarga → Escolha operadora → Digite número → Aguarde processamento\n\nDúvidas? Fale com @lssilvae",
                'parse_mode' => 'html'
            ]);
            continue;
        }
    }

    // === VERIFICA PAGAMENTOS PENDENTES ===
    foreach ($pagamentos as $pid => &$pg) {
        if ($pg['verificado']) continue;
        $ch = curl_init("$api_mp/$pid?access_token=$mp_token");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $res = json_decode(curl_exec($ch), true);
        curl_close($ch);
        
        if (isset($res['status']) && $res['status'] === 'approved') {
            list($usuario, $senha) = gerarCredenciais();
            enviar([
                'chat_id' => $pg['cid'],
                'text' => "✅ <b>PAGAMENTO CONFIRMADO!</b>\n\n👤 Usuário: <code>$usuario</code>\n🔑 Senha: <code>$senha</code>\n⏳ Validade: {$pg['plano']['dias']} dias\n\nAproveite!",
                'parse_mode' => 'html'
            ]);
            enviar([
                'chat_id' => $admin_id,
                'text' => "💰 PAGAMENTO CONFIRMADO\nUsuário: {$pg['cid']}\nPlano: {$pg['plano']['nome']}\nConta: $usuario / $senha"
            ]);
            $pg['verificado'] = true;
        }
    }
    sleep(1);
}