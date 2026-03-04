curl -s -X POST "https://api.cloudflare.com/client/v4/zones/zoneid/dns_records" \
     -H "X-Auth-Email: email" \
     -H "X-Auth-Key: globalapikey" \
     -H "Content-Type: application/json" \
     --data '{"type":"A","name":"unik.domain","content":"ipserver","ttl":120,"priority":10,"proxied":true}' > /dev/null
