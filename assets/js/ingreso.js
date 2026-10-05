/* Pantallas del ingreso y Mi cuenta: mostrar contraseña, aviso de Bloq Mayús,
   campo del código de 6 dígitos (con reloj de 30 s), copiar la clave de
   configuración y copiar/descargar los códigos de respaldo. Todo funciona
   también sin JavaScript (los formularios se envían igual). */
(() => {
  /* --- Ver / ocultar contraseña --- */
  document.querySelectorAll('[data-ver]').forEach(boton => {
    const campo = document.getElementById(boton.dataset.ver);
    if (!campo) return;
    boton.addEventListener('click', () => {
      const oculta = campo.type === 'password';
      campo.type = oculta ? 'text' : 'password';
      boton.setAttribute('aria-pressed', String(oculta));
      boton.setAttribute('aria-label', oculta ? 'Ocultar contraseña' : 'Mostrar contraseña');
      campo.focus();
    });
  });

  /* --- Bloq Mayús --- */
  document.querySelectorAll('[data-mayus]').forEach(aviso => {
    const campo = document.getElementById(aviso.dataset.mayus);
    if (!campo) return;
    const revisar = ev => {
      if (typeof ev.getModifierState !== 'function') return;
      aviso.classList.toggle('visible', ev.getModifierState('CapsLock'));
    };
    campo.addEventListener('keyup', revisar);
    campo.addEventListener('keydown', revisar);
    campo.addEventListener('blur', () => aviso.classList.remove('visible'));
  });

  /* --- Código de 6 dígitos: "482913" se ve "482 913"; con 6 dígitos se envía solo --- */
  document.querySelectorAll('form[data-codigo]').forEach(form => {
    const campo = form.querySelector('input[name="codigo"]');
    if (!campo) return;
    let enviado = false;
    campo.addEventListener('input', () => {
      const d = campo.value.replace(/\D+/g, '').slice(0, 6);
      campo.value = d.length > 3 ? d.slice(0, 3) + ' ' + d.slice(3) : d;
      if (d.length === 6 && !enviado) {
        enviado = true;
        form.requestSubmit ? form.requestSubmit() : form.submit();
      }
    });
  });

  /* --- Reloj: cuánto le queda al código actual (los códigos cambian a los :00 y :30) --- */
  const reloj = document.querySelector('[data-reloj]');
  if (reloj) {
    const arco  = reloj.querySelector('.reloj-arco');
    const texto = reloj.querySelector('span');
    const pintar = () => {
      const quedan = 30 - (Math.floor(Date.now() / 1000) % 30);
      if (arco) arco.style.strokeDasharray = quedan + ' 30';
      texto.textContent = 'Cambia en ' + quedan + ' s';
      reloj.classList.toggle('poco', quedan <= 5);
    };
    pintar();
    setInterval(pintar, 1000);
  }

  /* --- Copiar al portapapeles (con plan B: seleccionar el texto) --- */
  const copiar = async (texto, boton, nodo) => {
    const etiqueta = boton.querySelector('span') || boton;
    const antes = etiqueta.textContent;
    try {
      await navigator.clipboard.writeText(texto);
      etiqueta.textContent = 'Copiado';
    } catch (e) {
      if (nodo) {
        const rango = document.createRange();
        rango.selectNodeContents(nodo);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(rango);
      }
      etiqueta.textContent = 'Selecciónalo y cópialo';
    }
    setTimeout(() => { etiqueta.textContent = antes; }, 2200);
  };

  document.querySelectorAll('[data-copiar]').forEach(boton => {
    const nodo = document.getElementById(boton.dataset.copiar);
    boton.addEventListener('click', () => copiar(boton.dataset.valor || (nodo ? nodo.textContent : ''), boton, nodo));
  });

  const lista = document.querySelector('.codigos-respaldo[data-codigos]');
  if (lista) {
    const texto = lista.dataset.codigos;
    const botonCopiar = document.querySelector('[data-copiar-codigos]');
    if (botonCopiar) botonCopiar.addEventListener('click', () => copiar(texto, botonCopiar, lista));
    const botonBajar = document.querySelector('[data-descargar-codigos]');
    if (botonBajar) {
      botonBajar.addEventListener('click', () => {
        const url = URL.createObjectURL(new Blob([texto], { type: 'text/plain;charset=utf-8' }));
        const a = document.createElement('a');
        a.href = url;
        a.download = botonBajar.dataset.descargarCodigos;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
      });
    }
  }

  /* --- Casilla "los guardé": habilita el botón --- */
  document.querySelectorAll('[data-habilita]').forEach(casilla => {
    const boton = document.getElementById(casilla.dataset.habilita);
    if (!boton) return;
    const revisar = () => { boton.disabled = !casilla.checked; };
    casilla.addEventListener('change', revisar);
    revisar();
  });
})();
