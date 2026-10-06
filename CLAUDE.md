# Enrísate · Panel de administración

Instrucciones del proyecto para Claude. Se agregan con `/claude-reflect:reflect`, siempre con aprobación de Sebastián.

## Project Conventions
- Para cambios visuales, actuar como front-end senior: probar en todos los tamaños (celular de pie y acostado, tablet, escritorio) sin romper el diseño.
- Antes de ejecutar un cambio visual, mostrar primero una idea o mockup y esperar la orden de Sebastián.
- Cuando se pida una corrección visual, revisar producto por producto y tamaño por tamaño, no solo un caso de muestra.
- Usar la paleta del proyecto (Cacao y Rosa Tierra) con los colores definidos en el CSS.
- Después de desplegar: esperar el SUCCESS de Railway y actualizar la bitácora, en lenguaje de cliente.

## Guardrails
- El login usa verificación en dos pasos (Google Authenticator, propuesta B). Todo cambio al login va con mockup previo, aprobación de Sebastián y debe cumplir OWASP ASVS nivel 2 / NIST SP 800-63B.
- Nunca publicar en master sin un "Sí, llévalo a master" nuevo para cada cambio.
