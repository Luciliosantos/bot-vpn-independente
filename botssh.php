<?php
error_reporting(0);
ini_set('display_errors', 0);
set_time_limit(0);

if (!file_exists('dadosBot.ini')) {
    echo "❌ ERRO: dadosBot.ini não encontrado!\nExecute: bash instalar.sh\n";
    exit(1);
}

$cfg = parse_ini_file('dadosBot.ini', true);
if (!$cfg) { exit("❌ Arquivo de configuração inválido!\n"); }

$token     = trim($cfg['GERAL']['token']);
$admin_id  = (int)$cfg['GERAL']['admin_id'];
$painel_ip = trim($cfg['PAINEL_VPN']['ip']);
$ssh_user  = trim($cfg['PAINEL_VPN']['usuario_ssh']);
$ssh_pass  = trim($cfg['PAINEL_VPN']['senha_ssh']);
$caminho   = trim($cfg['PAINEL_VPN']['caminho'] ?? '/root/Sistema/');
$mp_token  = trim($cfg['MERCADO_PAGO']['access_token'] ?? '');

if (!$token || !$painel_ip || !$ssh_user || !$ssh_pass) {
    exit("❌ Preencha todos os dados em dadosBot.ini!\n");
}

$api_tg = "https://api.telegram.org/bot$token/";
$offset = 0;
$sessao = [];

echo "✅ BOT INICIADO — Conectando ao Painel: $painel_ip\n";

function executarNoPainel($comando) {
    global $painel_ip, $ssh_user, $ssh_pass, $caminho;
    $cmd = "sshpass -p '$ssh_pass' ssh -o StrictHostKeyChecking=no $ssh_user@$painel_ip " .
           "'cd $caminho && $comando' 2>&1";
    return shell_exec($cmd) ?: "Erro: sem resposta do painel";
}

function enviarMsg($chat_id, $texto, $botoes=null) {
    global $api_tg;
    $dados = ['chat_id'=>$chat_id, 'text'=>$texto, 'parse_mode'=>'Markdown'];
    if ($botoes) $dados['reply_markup'] = json_encode(['inline_keyboard'=>$botoes]);
    file_get_contents($api_tg.'sendMessage?'.http_build_query($dados));
}

function criarAcessoVPN($dados) {
    return executarNoPainel("./criarusuario '$dados[nome]' '$dados[validade]' '$dados[limite]'");
}

while (true) {
    $resp = file_get_contents($api_tg."getUpdates?offset=$offset&timeout=10");
    $updates = json_decode($resp, true)['result'] ?? [];
    
    foreach ($updates as $u) {
        $offset = $u['update_id'] + 1;
        $msg = $u['message'] ?? $u['callback_query']['message'] ?? null;
        if (!$msg) continue;
        
        $chat = $msg['chat']['id'];
        $txt = trim($msg['text'] ?? '');
        $cb = $u['callback_query']['data'] ?? '';

        if ($txt === '/start') {
            enviarMsg($chat, "👋 Bem-vindo! Escolha uma opção:", [
                [['text'=>'🔑 Gerar Acesso VPN','callback_data'=>'gerar']],
                [['text'=>'📊 Status do Painel','callback_data'=>'status']],
                [['text'=>'💳 Pagamento','callback_data'=>'pagamento']]
            ]);
        }
        elseif ($cb === 'status' && $chat==$admin_id) {
            $resp = executarNoPainel('ls -la');
            enviarMsg($chat, "📂 Painel acessível ✅\nResposta: ".substr($resp,0,300));
        }
        elseif ($cb === 'gerar') {
            $sessao[$chat] = 'nome';
            enviarMsg($chat, "🔑 Vamos criar seu acesso!\nDigite o NOME do usuário:");
        }
        elseif (($sessao[$chat] ?? '') === 'nome') {
            $sessao[$chat] = ['nome'=>$txt, 'etapa'=>'validade'];
            enviarMsg($chat, "✅ Nome: $txt\nDigite a VALIDADE em dias:");
        }
        elseif (is_array($sessao[$chat] ?? null) && $sessao[$chat]['etapa']==='validade') {
            $sessao[$chat]['validade'] = $txt;
            $sessao[$chat]['etapa'] = 'limite';
            enviarMsg($chat, "✅ Validade: $txt dias\nDigite o LIMITE de IPs:");
        }
        elseif (is_array($sessao[$chat] ?? null) && $sessao[$chat]['etapa']==='limite') {
            $sessao[$chat]['limite'] = $txt;
            $res = criarAcessoVPN($sessao[$chat]);
            enviarMsg($chat, "✅ ACESSO CRIADO!\n\n$res");
            unset($sessao[$chat]);
        }
    }
    sleep(1);
}
