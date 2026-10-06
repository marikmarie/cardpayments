
 
Press `i` to enter insert mode, then paste this:
 
```nginx
upstream cxm_api {
    server 127.0.0.1:3450;
    keepalive 16;
}
 
server {
    listen 80;
    listen [::]:80;
 
    server_name cxm.gdexperts.com;
 
    location ^~ /.well-known/acme-challenge/ {
        root /var/lib/letsencrypt;
        default_type text/plain;
        try_files $uri =404;
    }
 
    location ^~ /api/ {
        proxy_pass http://cxm_api;
 
        proxy_http_version 1.1;
 
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
 
        proxy_connect_timeout 30s;
        proxy_send_timeout 120s;
        proxy_read_timeout 120s;
    }
 
    location = /swagger {
        return 302 /swagger/index.html;
    }
 
    location ^~ /swagger/ {
        proxy_pass http://cxm_api;
 
        proxy_http_version 1.1;
 
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
 
        proxy_read_timeout 120s;
    }
 
    location ^~ /openapi/ {
        proxy_pass http://cxm_api;
 
        proxy_http_version 1.1;
 
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
 
        proxy_read_timeout 120s;
    }
 
    location / {
        return 404;
    }
}
```
 
Save in `vi`:
 
```text
Esc
:wq
Enter
```
 
### 2. Test that your backend actually works on 3450
 
Before touching SSL, run:
 
```bash
curl http://127.0.0.1:3450/swagger/index.html
```
 
Also try:
 
```bash
curl -I http://127.0.0.1:3450/swagger/index.html
```
 
You want a response from the application rather than `Connection refused`.
 
You can also confirm the port:
 
```bash
ss -lntp | grep 3450
```
 
### 3. Test Nginx configuration
 
Run:
 
```bash
nginx -t
```
 
You should get something similar to:
 
```text
syntax is ok
test is successful
```
 
If you get an error, **don't restart Nginx**. Send me the exact error.
 
If successful:
 
```bash
systemctl reload nginx
```
 
Then test:
 
```bash
curl -I http://cxm.gdexperts.com/swagger/
```
 
### 4. Set up HTTPS/SSL
 
First check whether Certbot exists:
 
```bash
certbot --version
```
 
If it does, you can normally obtain the certificate with:
 
```bash
certbot certonly --webroot -w /var/lib/letsencrypt -d cxm.gdexperts.com
```
 
After the certificate is successfully created, edit again:
 
```bash
vi /etc/nginx/conf.d/cxm.conf
```
 
Then replace the configuration with the final version below. I am using `3000` as a **placeholder** for the frontend port — don't activate that upstream until you know the real frontend port.
 
```nginx
upstream cxm_api {
    server 127.0.0.1:3450;
    keepalive 16;
}
 
server {
    listen 80;
    listen [::]:80;
 
    server_name cxm.gdexperts.com;
 
    location ^~ /.well-known/acme-challenge/ {
        root /var/lib/letsencrypt;
        default_type text/plain;
        try_files $uri =404;
    }
 
    location / {
        return 301 https://$host$request_uri;
    }
}
 
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
 
    server_name cxm.gdexperts.com;
 
    ssl_certificate /etc/letsencrypt/live/cxm.gdexperts.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/cxm.gdexperts.com/privkey.pem;
 
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;
 
    access_log /var/log/nginx/cxm-access.log;
    error_log /var/log/nginx/cxm-error.log warn;
 
    client_max_body_size 50m;
 
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
 
    # Backend API
    location ^~ /api/ {
        proxy_pass http://cxm_api;
 
        proxy_http_version 1.1;
 
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
 
        proxy_connect_timeout 30s;
        proxy_send_timeout 120s;
        proxy_read_timeout 120s;
    }
 
    # Redirect /swagger to Swagger UI
    location = /swagger {
        return 302 /swagger/index.html;
    }
 
    # Swagger UI
    location ^~ /swagger/ {
        proxy_pass http://cxm_api;
 
        proxy_http_version 1.1;
 
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
 
        proxy_read_timeout 120s;
    }
 
    # OpenAPI
    location ^~ /openapi/ {
        proxy_pass http://cxm_api;
 
        proxy_http_version 1.1;
 
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
 
        proxy_read_timeout 120s;
    }
 
    # FRONTEND WILL GO HERE LATER
    location / {
        return 404;
    }
}
```
 
Then:
 
```bash
nginx -t
```
 
and, only if successful:
 
```bash
systemctl reload nginx
```
 
Your Swagger should then be available at:
 
`https://cxm.gdexperts.com/swagger`
 
and your API endpoints at:
 
`https://cxm.gdexperts.com/api/...`
 
### When your frontend is ready
 
Suppose, for example, you run it on port `3451`. Add this near the top:
 
```nginx
upstream cxm_frontend {
    server 127.0.0.1:3451;
    keepalive 16;
}
```
 
Then replace the `location / { return 404; }` section with:
 
```nginx
location / {
    proxy_pass http://cxm_frontend;
 
    proxy_http_version 1.1;
 
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-Host $host;
 
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
 
    proxy_connect_timeout 30s;
    proxy_send_timeout 120s;
    proxy_read_timeout 120s;
}
```
 
For now, **do steps 1–3 first**. In particular, run:
 
```bash
curl -I http://127.0.0.1:3450/swagger/index.html
nginx -t
```
 
Send me what those **two commands return**, and I'll take you through the SSL and frontend part without risking the working Nginx sites.
 