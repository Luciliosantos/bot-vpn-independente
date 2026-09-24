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

