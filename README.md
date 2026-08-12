# Mafer Grade — Landing de conversão

Backup versionado da landing page oficial da Mafer Grade para gradis, cercamentos, grades industriais e tubos de aço.

## Versão atual

- versão: `2.0.3`;
- publicação oficial: https://mafergrade.com.br/gradis-e-cercamentos/;
- tag Git: `v2.0.3`;
- atualização: 12 de agosto de 2026.

## Conteúdo do repositório

- `index.html`: versão estática da landing para consulta e pré-visualização;
- `assets/`: imagens e fontes usadas pela página;
- `wordpress/mafergrade-landing/`: código-fonte do plugin publicado no WordPress;
- `wordpress/mafergrade-landing-2.0.3.zip`: pacote instalável da versão publicada;
- `automation/lead-capture-apps-script.gs`: código do webhook que envia os contatos para a aba `Mafer Grade` da planilha de leads.

## Conversão e mensuração

- formulário curto com nome, WhatsApp e linha de interesse;
- gravação local no WordPress antes da sincronização com a planilha;
- fila com retentativas e proteção contra duplicidade;
- Google Tag `GT-NSLWH83J`;
- Google Ads `AW-386301164`;
- Google Tag Manager `GTM-K4K4H8QQ`;
- preservação de UTMs, ValueTrack, `gclid`, `gbraid` e `wbraid`;
- variantes de anúncio por `?linha=gradis`, `?linha=grades` e `?linha=tubos`.

## Publicação

O WordPress usa o pacote em `wordpress/mafergrade-landing-2.0.3.zip`. A versão estática da raiz funciona como backup legível e não substitui o deploy oficial.
