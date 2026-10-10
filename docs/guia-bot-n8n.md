# Guía: bot de WhatsApp en n8n

_Para armarlo desde el PC, paso a paso. Nada de aquí contiene llaves ni contraseñas._

## Dónde estamos (10 de octubre, 2026)

- **Twilio** (cuenta de prueba) recibe lo que escribes al chat "Twilio Trial", número **+1 737 250 8034**, y se lo pasa a n8n.
- **n8n** (proyecto zucchini-victory en Railway): `https://n8n-production-3e78.up.railway.app`. Workflow activo con estos nodos: **Webhook → Code → HTTP Request → Respond to Webhook**.
- **Code** ("el cerebro", etapa 1): ya decide si el mensaje es saludo, catálogo, cuenta o producto. Probado.
- **HTTP Request** (envío): está **apagado** para no gastar mensajes de prueba. Manda la plantilla de ejemplo de Twilio (`ContentSid`). Con la cuenta de prueba **solo se pueden enviar plantillas**, no texto libre; el texto libre necesita un número aprobado por Meta.
- **La API de Enrísate** (catálogo, clienta, consumo) ya está publicada en Railway, pero cerrada hasta crear `API_TOKEN`.

## Paso 1 · Crear la llave (en el PC)

1. Abre una terminal en la carpeta del proyecto (XAMPP trae PHP, en `XAMPP\php\php.exe`) y corre:
   `php tools/generar_token_api.php`
   Te imprime 64 caracteres. Esa es la llave. **No la compartas ni la pegues en el chat.**
2. **Railway** → proyecto `poetic-reflection` → servicio `enrisate-admin` → **Variables** → **New Variable**: nombre `API_TOKEN`, valor = la llave. Al guardar, el servicio se reinicia (1 o 2 minutos).
3. **n8n** → **Credentials** → **Create** → **Header Auth**: Name `X-Api-Key`, Value = la misma llave. Ponle de nombre "API Enrísate".
4. Comprueba que abre (en la terminal de Windows, cambiando TU_LLAVE):
   `curl.exe -H "X-Api-Key: TU_LLAVE" "https://enrisate-admin-production.up.railway.app/api-consumo.php"`
   Debe salir un JSON con `"ok":true`. Si sale `503` la variable no se guardó o el servicio no reinició; si sale `401`, la llave está mal copiada.

## Paso 2 · Buscar productos (etapa 2)

Cuando el cerebro dice `intencion = producto`, se consulta el catálogo.

**a) Nodo IF** (después de Code): condición `{{ $json.intencion }}` igual a `producto`.
- **true** → va al nodo b).
- **false** → va directo al nodo de envío (HTTP Request). Un nodo puede recibir dos conexiones; solo corre la rama que se ejecutó.

**b) Nodo HTTP Request "Buscar producto"**
- Method `GET`, URL `https://enrisate-admin-production.up.railway.app/api-catalogo.php`
- Authentication: *Generic Credential Type* → **Header Auth** → "API Enrísate"
- Send Query Parameters: `q` = `{{ $json.original }}` (lo que escribió la clienta, sin tocar) y `limite` = `3`

**c) Nodo Code "Armar respuesta"** (`Run Once for Each Item`, JavaScript). Si el primer Code no se llama "Code", cambia el nombre en `$('Code')`:

```javascript
const api = $input.item.json;
const productos = api.productos || [];
let respuesta;

if (api.coincidencia === 'ninguna' || productos.length === 0) {
  respuesta = 'No encontré ese producto 😕 Pídeme otro nombre (por ejemplo "shampoo para rizos") o mira el catálogo: ' + api.catalogo_url;
} else {
  const intro = api.coincidencia === 'todas' ? 'Esto encontré para ti 💛' : 'No tengo justo eso, pero mira estos:';
  const lineas = productos.map(p => `• ${p.marca} ${p.nombre} — ${p.precio_texto}`);
  respuesta = [intro, ...lineas, '', '🎁 ' + api.promo.texto].join('\n');
}

return { json: { telefono: $('Code').item.json.telefono, respuesta } };
```

Este nodo se conecta al nodo de envío (HTTP Request), igual que la rama false del IF.

**Probar la etapa 2:** con el nodo de envío apagado, escribe `shampoo para rizos` al chat y en **Executions** mira el nodo "Armar respuesta": `respuesta` debe traer 1 a 3 productos con su precio.

## Paso 3 · La cuenta de la clienta (etapa 3, después)

Cuando `intencion = cuenta`, se consulta `api-cliente.php` con la cabecera `X-Cliente-Telefono` = `{{ $('Code').item.json.telefono }}` (más la llave).

**No la actives para hablar de saldos o deudas** hasta que n8n compruebe la firma `X-Twilio-Signature`. Sin eso, alguien que conozca la dirección del webhook puede hacerse pasar por otra clienta. Mientras tanto, úsala solo para saludar por el nombre y recomendar productos.

## Paso 4 · Cuando haya número aprobado por Meta

1. Enciende o reemplaza el nodo de envío. Con texto libre el cuerpo del mensaje es `Body` = `{{ $json.respuesta }}` (ya no `ContentSid`).
2. Cambia `From` por el número nuevo.
3. Respeta a quien dijo NO a las promociones (`acepta_promociones` de la API) antes de mandar mensajes que no pidió.

## Pendientes

- [ ] Crear `API_TOKEN` (Paso 1).
- [ ] Etapa 2: buscar productos.
- [ ] Verificar la firma de Twilio.
- [ ] Facebook / Meta: usar la cuenta con normalidad desde el celular unos días y después crear la cuenta de negocio.
- [ ] Encender de nuevo el nodo de envío cuando se quiera probar con un mensaje real.
