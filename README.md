# bot-vpn-independente

# script de instalação 






#1

# Atualiza e instala dependências
apt update -y && apt install php-cli php-curl wget -y

# Cria pasta
mkdir -p /root/bot
cd /root/bot

# Cria o serviço systemd
cat > /etc/systemd/system/bot-ssh.service << 'EOF'
[Unit]
Description=Bot SSH Telegram
After=network.target

[Service]
Type=simple
User=root
WorkingDirectory=/root/bot
ExecStart=/usr/bin/php /root/bot/botssh.php
Restart=always
RestartSec=3
StartLimitBurst=10
StartLimitIntervalSec=5

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
echo "✅ VPS PRONTA!"

#2

systemctl stop bot-ssh.service &&
wget -qO /root/bot/botssh.php https://raw.githubusercontent.com/Luciliosantos/bot-vpn-independente/main/botssh.php &&
chmod +x /root/bot/botssh.php &&
sed -i 's/\r$//' /root/bot/botssh.php &&
systemctl daemon-reload &&
systemctl start bot-ssh.service &&
echo "✅ ATUALIZADO COM SUCESSO!" &&
systemctl status bot-ssh.service --no-pager


# comando de atualização

wget -qO /root/bot/botssh.php https://raw.githubusercontent.com/Luciliosantos/bot-vpn-independente/main/botssh.php &&
systemctl restart bot-ssh.service &&
echo "✅ Atualizado!"








#🚀 COMANDO DE INSTALAÇÃO BOT AFILIADO — COPIA E COLA NA VPS


apt update -y && apt install php-cli php-sqlite3 php-curl wget screen -y

mkdir -p /root/bot-afiliados && cd /root/bot-afiliados

# Baixa o código limpo do GitHub
wget -qO bot.php https://raw.githubusercontent.com/Luciliosantos/bot-vpn-independente/main/bot.php

# Cria o arquivo SECRETO com seus dados (SÓ NA VPS!)
cat > dadosBot.ini << 'EOF'
token=COLOQUE_SEU_TOKEN_DO_TELEGRAM_AQUI
admin_id=7761133138
mp_token=COLOQUE_SEU_TOKEN_DO_MERCADO_PAGO_AQUI
preco_premium=19.00
EOF

# Verifica
php -l bot.php && echo "✅ Código OK!"
echo "📋 Dados carregados:"
cat dadosBot.ini

# Limpa e inicia
pkill -9 -f "php bot.php" 2>/dev/null
rm -f dados.db 2>/dev/null
screen -dmS bot bash -c 'while true; do php bot.php; sleep 3; done'

echo ""
echo "✅ BOT INSTALADO E RODANDO! 🟢"
echo "👉 Ver rodando: screen -r bot"
echo "👉 Sair sem parar: Ctrl + A → depois D"



#🔄 PARTE 3 — ATUALIZAR O CÓDIGO (quando mudar no GitHub)

cd /root/bot-afiliados && pkill -9 -f bot.php &&
wget -qO bot.php https://raw.githubusercontent.com/Luciliosantos/bot-vpn-independente/main/bot.php &&
php -l bot.php &&
screen -dmS bot bash -c 'while true; do php bot.php; sleep 3; done' &&
echo "✅ CÓDIGO ATUALIZADO! Dados protegidos 🔒"


#🔑 PARTE 4 — ATUALIZAR SOMENTE OS DADOS (token/preço)

cd /root/bot-afiliados
nano dadosBot.ini

Altera →  Ctrl+O  →  Enter  →  Ctrl+X  → reinicia:

pkill -9 -f bot.php &&
screen -dmS bot bash -c 'while true; do php bot.php; sleep 3; done' &&
echo "✅ DADOS ATUALIZADOS! 🔒"
