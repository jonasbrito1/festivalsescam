# Festival Jurados - Wrapper Capacitor

Esta pasta e separada do sistema principal e da copia PWA.

## Objetivo

Gerar o app Android a partir da versao web publicada em:

```text
mobile_pwa/
```

## Configuracao da URL

1. copie `.env.example` para `.env`
2. ajuste:

```text
MOBILE_PWA_URL=https://seu-dominio.com/FESTIVAL_CALOUROS2/mobile_pwa/
```

## Comandos

```bash
npm install
npx cap add android
npx cap sync
npx cap open android
```

## URL local atual de teste

Esta instalacao ficou apontada para:

```text
http://SEU-IP-LOCAL:3001/FESTIVAL_CALOUROS2/mobile_pwa/
```

## Observacao

Para tablet ou celular Android real, `localhost` nao funciona. O APK deve apontar para uma URL HTTPS acessivel pelo dispositivo.
