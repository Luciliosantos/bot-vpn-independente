# bot-vpn-independente

# script de instalação 



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

