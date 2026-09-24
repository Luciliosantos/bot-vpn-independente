#!/bin/bash
clear
echo "======================================"
echo "   INSTALADOR BOT VPN SSH"
echo "======================================"
echo ""

# Instala dependências
apt update -y
apt install php-cli php-curl wget -y

# Cria pasta
mkdir -p /root/bot
cd /root/bot

# Baixa arquivos
wget -qO botssh.php https://raw.githubusercontent.com/Luciliosantos/bot-vpn-independente/main/botssh.php

# Executa
echo "✅ Arquivos baixados!"
echo ""
echo "👉 Para iniciar: cd /root/bot && php botssh.php"
