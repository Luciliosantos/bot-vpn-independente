# bot-vpn-independente

# script de instalação 


apt update -y && apt install php-cli php-curl wget screen -y &&
mkdir -p /root/bot && cd /root/bot &&
wget -qO botssh.php https://raw.githubusercontent.com/Luciliosantos/bot-vpn-independente/main/botssh.php &&
php -l botssh.php &&
screen -dmS bot bash -c 'while true; do php botssh.php; sleep 5; done' &&
echo "" &&
echo "✅ BOT INSTALADO E RODANDO!" &&
echo "======================================" &&
echo "👉 Para SAIR sem PARAR o bot:" &&
echo "   APERTE: Ctrl + A  DEPOIS APERTE: D" &&
echo "======================================" &&
echo "" &&
echo "Para voltar a ver o bot depois:" &&
echo "screen -r bot" &&
echo "" &&
screen -r bot
