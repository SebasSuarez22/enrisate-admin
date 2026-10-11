# Guía: bot de WhatsApp en n8n

_Para armarlo desde el PC, paso a paso. Nada de aquí contiene llaves ni contraseñas._

## Dónde estamos (10 de octubre, 2026)

- **Twilio** (cuenta de prueba) recibe lo que escribes al chat "Twilio Trial", número **+1 737 250 8034**, y se lo pasa a n8n.
- **n8n** (proyecto zucchini-victory en Railway): `https://n8n-production-3e78.up.railway.app`. Workflow activo con estos nodos: **Webhook → Code → HTTP Request → Respond to Webhook**.
- **Code** ("el cerebro", etapa 1): ya decide si el mensaje es saludo, catálogo, cuenta o producto. Probado.
- **HTTP Request** (envío): está **apagado** para no gastar mensajes de prueba. Manda la plantilla de ejemplo de Twilio (`ContentSid`). Con la cuenta de prueba **solo se pueden enviar plantillas**, no texto libre; el texto libre necesita un número aprobado por Meta.
- **La API de Enrísate** (catálogo, clienta, consumo) está publicada en Railway y **abierta con `API_TOKEN`** (creada el 10 de oct; la llave vive solo en Railway y en la credencial "API Enrísate" de n8n).
- **Etapa 2 hecha y probada:** `shampoo para rizos` → If (true) → HTTP Request1 (api-catalogo) → Armar Respuesta. Devuelve productos reales con precio. Nombres de nodos tal como están: "Code in JavaScript", "If", "HTTP Request1", "Armar Respuesta", "HTTP Request" (envío, apagado), "Respond to Webhook".

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

## El número de WhatsApp del bot (decisión pendiente)

**Idea de Sebastián:** el 305 241 8417 ya está en la app WhatsApp Business. Si el bot se queda corto, una persona toma el chat desde la app y supervisa el trabajo del bot todos los días.

**Lo que se investigó (11 de oct, 2026).** Fuentes: principalmente proveedores, no Meta; no se pudieron abrir las páginas oficiales. Confirmar antes de decidir.
- **Twilio normal no permite compartir el número.** Su guía de migración pide **eliminar la cuenta de WhatsApp** del número antes de registrarlo como remitente. Después no se puede seguir usando en WhatsApp ni en la app Business. Consecuencia: Gina dejaría de ver el 305 en el celular y los pedidos del catálogo (que van a ese número) le llegarían al bot.
- **Coexistence** (función de Meta) sí permite app + API en el mismo número. Se conecta con Embedded Signup ("conectar cuenta existente de WhatsApp Business", con un código que sale en la app) y la hacen intermediarios autorizados de Meta (360dialog, Infobip, Respond.io, Wati, etc.).
- **Dudas sin resolver:** ¿está disponible para Colombia (+57)? Meta no publica lista; la elegibilidad se revisa por número al registrarlo. ¿Twilio la soporta? No hay documentación; un blog de la competencia dice que no.
- **Límites** (según proveedores, pueden variar): abrir la app al menos cada 13 o 14 días (Sebastián la abriría a diario, así que no es problema); no sirven mensajes temporales ni de vista única; los grupos no se sincronizan con la API; las listas de difusión quedan limitadas; el historial se comparte hasta unos 6 meses si se autoriza; si se desinstala la app, se desconecta; tope de 20 mensajes por segundo.

**Dos caminos:**
1. **Número nuevo para el bot + entrega a una persona (el más seguro).** El bot usa una línea nueva (prepago, que no esté en WhatsApp) con Twilio. Cuando no puede ayudar, responde con un enlace `https://wa.me/573052418417?text=...` y Gina atiende en la app del 305. El 305 no se toca.
2. **Coexistence en el 305** con un proveedor que lo soporte. Implica cambiar de proveedor (no Twilio, salvo que confirme) y rehacer el nodo de envío.

**Preguntas listas para enviar**

_A Twilio (soporte o chat de ventas; la cuenta de prueba puede tener soporte limitado):_
> Hello, we want to use our existing WhatsApp Business app number, +57 305 241 8417 (Colombia), with the WhatsApp Business Platform through Twilio, while continuing to use the WhatsApp Business app on the same number (WhatsApp Business app coexistence).
> 1. Does Twilio support coexistence today?
> 2. If yes, is it available for Colombian (+57) numbers, and how do we onboard (Self Sign-up or Embedded Signup)?
> 3. If not, is it planned? Is there any way to keep the app active on the same number?
> 4. Which Twilio plan or upgrade is required?
> Thank you.

_A un proveedor alternativo (360dialog, Respond.io, Infobip…):_
> Hello, we have a WhatsApp Business app number in Colombia (+57 305 241 8417) and want to connect it to the API using coexistence, keeping the app in use. Please confirm:
> 1. Is coexistence available for +57 numbers?
> 2. What are the setup steps and the monthly cost?
> 3. Can we receive messages through a webhook and reply with free-form text (inside the 24-hour window) from n8n?
> 4. What happens to our existing chats and contacts?

**Respuesta de Twilio (11 de oct, 2026; parece automática, un agente humano debería confirmarla por escrito antes de tocar el 305):**
- Coexistence **no está soportada**: al registrar el número en la API, la app WhatsApp Business deja de funcionar en él. Sin fecha prevista.
- Los números de Colombia (+57) **sí** se pueden registrar para la API. Hay Self Sign-up y Embedded Signup.
- Precio: pago por uso, desde US$0,005 por mensaje más las tarifas de Meta. Dicen que no hace falta un plan especial (ojo: la cuenta de prueba solo dejó enviar la plantilla de ejemplo; confirmarlo al registrar).
- Dicen que ningún proveedor permite mantener la app. Esto **contradice** la documentación de 360dialog, Infobip y Respond.io, que sí describen Coexistence.

**Candidata: 360dialog** (revisión del 11 de oct; confirmar todo en su página).
- **Coexistence documentado.** En el registro se elige "conectar tu cuenta existente de WhatsApp Business" y se escanea un QR que llega a la app. Pide la versión más reciente de la app, un celular con cámara, y datos del negocio (nombre legal, dirección, sitio web).
- **El Business Manager debe ser el de la empresa y no se puede cambiar después** de registrar el número. Usar el correcto.
- Abrir la app al menos cada 13 días. El historial de chats se conserva. Para escribirle primero a una clienta (fuera de las 24 h) hace falta plantilla aprobada.
- **Colombia no se menciona** (solo excluyen Nigeria y Sudáfrica). Sigue sin confirmar.
- **Costo:** planes por número y por mes, más las tarifas de Meta por mensaje. En su página: Regular €49, Premium €99, Scale €500; otra página dice desde US$59. Hay fuentes que hablan de un recargo de US$0,005 por mensaje y ellos dicen que no hay recargo. Dato fijo mensual, a diferencia de Twilio.
- **n8n:** soportado (nodo de comunidad "360Dialog" con una API key, o nodos HTTP normales con POST a `waba-v2.360dialog.io`). El webhook de entrada debe ser HTTPS y responder 200 de inmediato.
- **Cambios en el bot si se usa 360dialog:** (1) el Webhook recibe el formato de Meta, no el de Twilio: el texto y el teléfono vienen en `entry[0].changes[0].value.messages[0]` (`text.body` y `from`); hay que ajustar el nodo "Code in JavaScript". (2) El nodo de envío pasa de Twilio a 360dialog con texto libre (`"type": "text"`), no `ContentSid`. (3) "Respond to Webhook" devuelve un 200 vacío en vez de TwiML.
- **Prueba gratis:** no se encontró una para un número de cliente (el sandbox que documentan es para partners).

**Pasos para decidir:** (1) enviar las preguntas de arriba a 360dialog y pedir el precio exacto; (2) pedir a Twilio que un agente humano confirme la respuesta; (3) comparar costo mensual y decidir entre número nuevo con Twilio o el 305 con 360dialog.

**La forma más rápida de saberlo:** muchos proveedores tienen prueba gratis. Al empezar el registro del 305, Meta indica en el momento si el número es elegible para Coexistence.

## Pendientes

- [x] Crear `API_TOKEN` (Paso 1).
- [x] Etapa 2: buscar productos.
- [ ] Decidir el número del bot (ver la sección anterior): Twilio ya respondió que NO hay Coexistence; falta preguntarle a 360dialog (Colombia + precio exacto) o conseguir una línea nueva.
- [ ] Opcional: que en la búsqueda salgan primero los productos con la palabra en el **nombre** (hoy salen por orden alfabético de marca).
- [ ] Verificar la firma de Twilio.
- [ ] Facebook / Meta: usar la cuenta con normalidad desde el celular unos días y después crear la cuenta de negocio.
- [ ] Encender de nuevo el nodo de envío cuando se quiera probar con un mensaje real.
