#!/bin/bash
clear
echo "================================================"
echo "   INSTALADOR BOT - INDEPENDENTE DO PAINEL"
echo "================================================"
echo ""

apt update -y && apt install php-cli php-curl sshpass -y 2>/dev/null

echo "📋 DADOS DE CONEXÃO:"
read -p "🔑 Token do Bot Telegram: " TOKEN
read -p "👤 Seu ID Telegram (admin): " ADMIN_ID
read -p "🌐 IP da VPS com o Painel VPN: " PAINEL_IP
read -p "👤 Usuário SSH do Painel [root]: " SSH_USER
SSH_USER=${SSH_USER:-root}
read -s -p "🔒 Senha SSH do Painel: " SSH_PASS
echo ""
read -p "📂 Caminho dos arquivos do painel [/root/Sistema]: " CAMINHO
CAMINHO=${CAMINHO:-/root/Sistema}
read -p "💰 Token Mercado Pago (opcional): " MP_TOKEN

echo ""
echo "💾 Salvando configuração..."
cat > dadosBot.ini << EOF
[GERAL]
token = $TOKEN
admin_id = $ADMIN_ID

[PAINEL_VPN]
ip = $PAINEL_IP
usuario_ssh = $SSH_USER
senha_ssh = $SSH_PASS
caminho = $CAMINHO

[MERCADO_PAGO]
access_token = $MP_TOKEN
EOF

chmod 600 dadosBot.ini
echo "✅ Configuração salva!"
echo ""
echo "🚀 Iniciando o BOT..."
nohup php botssh.php > bot.log 2>&1 &
echo "✅ BOT ATIVADO!"
echo "📝 Logs: tail -f bot.log"
