(() => {
  const config = window.LIGNA_SITE_CONFIG || {};
  const header = document.querySelector('[data-header]');
  const navToggle = document.querySelector('[data-nav-toggle]');
  const nav = document.querySelector('[data-nav]');
  const navLinks = [...document.querySelectorAll('.site-nav a')];
  const sections = navLinks.map(link => document.querySelector(link.getAttribute('href'))).filter(Boolean);

  const onScroll = () => {
    header?.classList.toggle('is-scrolled', window.scrollY > 18);
    const current = sections.find(section => {
      const rect = section.getBoundingClientRect();
      return rect.top <= 130 && rect.bottom >= 130;
    });
    navLinks.forEach(link => link.classList.toggle('is-active', current && link.getAttribute('href') === `#${current.id}`));
  };

  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  navToggle?.addEventListener('click', () => {
    const isOpen = nav?.classList.toggle('is-open');
    navToggle.setAttribute('aria-expanded', String(Boolean(isOpen)));
  });

  navLinks.forEach(link => link.addEventListener('click', () => {
    nav?.classList.remove('is-open');
    navToggle?.setAttribute('aria-expanded', 'false');
  }));

  const revealObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.classList.add('is-visible');
        revealObserver.unobserve(entry.target);
      }
    });
  }, { threshold: 0.14 });
  document.querySelectorAll('.reveal').forEach(el => revealObserver.observe(el));

  const accordion = document.querySelector('[data-accordion]');
  accordion?.addEventListener('click', event => {
    const button = event.target.closest('button');
    if (!button) return;
    const item = button.closest('.accordion-item');
    const isOpen = item.classList.toggle('is-open');
    button.setAttribute('aria-expanded', String(isOpen));
  });

  const modal = document.querySelector('[data-modal]');
  const modalTitle = document.querySelector('[data-modal-title]');
  const modalMessage = document.querySelector('[data-modal-message]');
  const modalClose = document.querySelector('[data-modal-close]');
  const modalOk = document.querySelector('[data-modal-ok]');

  const showModal = (title, message, type = 'success') => {
    if (!modal) return;
    modalTitle.textContent = title;
    modalMessage.textContent = message;
    modal.dataset.type = type;
    modal.removeAttribute('hidden');
    modalOk?.focus();
  };

  const closeModal = () => modal?.setAttribute('hidden', '');
  modalClose?.addEventListener('click', closeModal);
  modalOk?.addEventListener('click', closeModal);
  modal?.addEventListener('click', event => {
    if (event.target === modal) closeModal();
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && modal && !modal.hasAttribute('hidden')) closeModal();
  });

  const form = document.querySelector('#contactForm');
  const formStatus = document.querySelector('[data-form-status]');
  const formSubmit = document.querySelector('[data-form-submit]');

  form?.addEventListener('submit', async event => {
    event.preventDefault();

    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }

    const endpoint = config.formEndpoint || form.getAttribute('action');
    if (!endpoint) {
      showModal('Formulario pendiente de conexión', 'Falta configurar el endpoint de envío del formulario.', 'error');
      return;
    }

    const originalText = formSubmit?.textContent || 'Enviar consulta';
    formSubmit.disabled = true;
    formSubmit.textContent = 'Enviando...';
    if (formStatus) formStatus.textContent = 'Enviando consulta.';

    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        body: new FormData(form),
        headers: { 'Accept': 'application/json' }
      });

      let result = {};
      try { result = await response.json(); } catch (_) { result = {}; }

      if (!response.ok || result.ok === false) {
        throw new Error(result.message || 'No se pudo enviar el mensaje.');
      }

      form.reset();
      showModal('Mensaje enviado correctamente', 'Gracias por escribirnos. Hemos recibido tu consulta y revisaremos la viabilidad técnica del proyecto.', 'success');
      if (formStatus) formStatus.textContent = 'Consulta enviada correctamente.';
    } catch (error) {
      showModal('No se pudo enviar el mensaje', 'El servidor no pudo procesar el envío. Puedes escribirnos directamente por WhatsApp o a info@lignamilano.com.', 'error');
      if (formStatus) formStatus.textContent = 'Error de envío.';
      console.error(error);
    } finally {
      formSubmit.disabled = false;
      formSubmit.textContent = originalText;
    }
  });

  const year = document.querySelector('#year');
  if (year) year.textContent = new Date().getFullYear();

  const chatbot = document.querySelector('[data-chatbot]');
  const chatLauncher = document.querySelector('[data-chat-launcher]');
  const chatClose = document.querySelector('[data-chat-close]');
  const chatBody = document.querySelector('[data-chat-body]');
  const chatForm = document.querySelector('[data-chat-form]');
  const chatInput = chatForm?.querySelector('input[name="chatMessage"]');
  const chatSubmit = chatForm?.querySelector('button[type="submit"]');
  const whatsappHandoff = document.querySelector('[data-whatsapp-handoff]');
  const emailHandoff = document.querySelector('[data-email-handoff]');
  const chatHistory = [];

  const getSessionId = () => {
    const key = 'ligna_milano_chat_session';
    let id = '';
    try { id = window.localStorage.getItem(key) || ''; } catch (_) { id = ''; }
    if (!id) {
      id = `lm-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
      try { window.localStorage.setItem(key, id); } catch (_) {}
    }
    return id;
  };
  const sessionId = getSessionId();

  const makeWhatsAppUrl = (message) => {
    const number = config.whatsappNumber || '34615304350';
    return `https://wa.me/${number}?text=${encodeURIComponent(message)}`;
  };

  const getTranscript = () => chatHistory.slice(-18).map(item => `${item.role}: ${item.text}`).join('\n');

  const refreshWhatsAppLink = () => {
    if (!whatsappHandoff) return;
    const trail = getTranscript();
    const base = config.whatsappDefaultMessage || 'Hola Ligna Milano, vengo desde la web y quiero consultar por un proyecto personalizado.';
    const message = trail ? `${base}\n\nResumen del chat:\n${trail}` : base;
    whatsappHandoff.href = makeWhatsAppUrl(message);
  };

  const appendMessage = (text, type = 'bot', options = {}) => {
    if (!chatBody || !text) return null;
    const node = document.createElement('p');
    node.className = type === 'user' ? 'user-message' : 'bot-message';
    if (options.typing) node.classList.add('typing-message');
    node.textContent = text;
    chatBody.appendChild(node);
    if (!options.skipHistory) {
      chatHistory.push({ role: type === 'user' ? 'Cliente' : 'Asistente', text });
    }
    chatBody.scrollTop = chatBody.scrollHeight;
    refreshWhatsAppLink();
    return node;
  };

  const fallbackAnswer = (text) => {
    const value = String(text || '').toLowerCase();
    const transcript = chatHistory.map(item => item.text).join(' ').toLowerCase();
    const hasProject = /(cubo|placa|botella|llavero|billetera|cartera|adorno|imagen|foto|grabado|acrílico|acrilico|vidrio|madera|metal|cuero)/u.test(transcript);
    const hasUse = /(regalo|empresa|corporativo|evento|decoración|decoracion|prototipo)/u.test(transcript + ' ' + value);

    if (/^\s*(hola|buenas|buenos días|buenos dias|buenas tardes|buenas noches)\s*[!.]?\s*$/u.test(value)) {
      return 'Hola, encantado. Soy el asistente de Ligna Milano. ¿Cómo te llamas y qué te gustaría personalizar?';
    }
    if (/^\s*(gracias|muchas gracias|ok gracias|perfecto gracias)\s*[!.]?\s*$/u.test(value)) {
      return hasProject
        ? 'De nada. Tengo el contexto del proyecto. Para avanzar, puedo enviar esta conversación al equipo o seguir afinando medidas, cantidad, material y archivo.'
        : 'De nada. Cuando quieras, dime qué objeto te gustaría personalizar y te ayudo a cerrar el briefing.';
    }
    if (value.includes('humano') || value.includes('persona') || value.includes('whatsapp') || value.includes('urgente')) {
      return 'Claro. Puedo preparar la derivación con la conversación completa para que el equipo tenga contexto y no tengas que repetirlo todo.';
    }
    if (hasProject && hasUse && value.length <= 30) {
      return 'Perfecto, lo tomo como uso del proyecto. Ya tengo parte del briefing. Para avanzar necesito una cosa concreta: ¿qué tamaño aproximado tendría y cuántas unidades serían?';
    }
    if (value.includes('precio') || value.includes('presupuesto') || value.includes('coste') || value.includes('cotización') || value.includes('cotizacion')) {
      return 'Para orientarte necesito producto, cantidad, material, medidas aproximadas, técnica deseada y fecha de entrega. Con esos datos se puede valorar viabilidad y coste con criterio.';
    }
    if (value.includes('logo') || value.includes('archivo') || value.includes('vector')) {
      return 'Para producción lo ideal es SVG, PDF, AI o EPS. Si tienes PNG o JPG, lo revisamos y te decimos si sirve o si conviene vectorizar.';
    }
    if (value.includes('3d') || value.includes('impresión') || value.includes('impresion')) {
      return 'Para impresión 3D necesito medidas, uso de la pieza, color, acabado y si es prototipo o serie corta. Una referencia visual acelera mucho la validación.';
    }
    if (value.includes('uv') || value.includes('dtf') || value.includes('botella')) {
      return 'Para UV DTF o impresión UV necesito superficie, material, medidas imprimibles y arte final. Es una gran opción para botellas, packaging, placas y objetos rígidos.';
    }
    if (value.includes('láser') || value.includes('laser') || value.includes('grabado') || value.includes('corte')) {
      return 'Para láser necesito material, grosor, zona de grabado o corte, cantidad y nivel de detalle. Con eso definimos diodo, fibra, MOPA o UV.';
    }
    return hasProject
      ? 'Entendido. Ya tengo contexto del proyecto. Para seguir sin repetirnos, dime cantidad, medidas aproximadas y si tienes imagen o archivo listo.'
      : 'Puedo ayudarte a cerrar el briefing. Cuéntame qué objeto quieres personalizar y, si puedes, cantidad, material y fecha deseada.';
  };

  const setChatBusy = (busy) => {
    if (chatSubmit) chatSubmit.disabled = busy;
    if (chatInput) chatInput.disabled = busy;
    if (emailHandoff) emailHandoff.disabled = busy;
    if (whatsappHandoff) whatsappHandoff.classList.toggle('is-disabled', busy);
  };

  const callAiAssistant = async (message, action = 'message') => {
    const endpoint = config.chatEndpoint || 'chat-ai.php';
    const currentHistory = action === 'message' ? chatHistory.slice(0, -1) : chatHistory;
    const payload = {
      action,
      message,
      sessionId,
      page: window.location.href,
      history: currentHistory.slice(-18)
    };

    const response = await fetch(endpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify(payload)
    });

    let result = {};
    try { result = await response.json(); } catch (_) { result = {}; }

    if (!response.ok || result.ok === false) {
      throw new Error(result.message || 'El asistente no respondió.');
    }
    if (result.mode && !String(result.mode).startsWith('openai')) {
      console.warn('Ligna Milano chatbot en modo local. Revisa OPENAI_API_KEY, facturación o logs del servidor.', result.debugReason || result.mode);
    }
    return result;
  };

  const processChatMessage = async (text, action = 'message') => {
    if (!text) return;
    appendMessage(text, 'user');
    const typingNode = appendMessage('Escribiendo...', 'bot', { typing: true, skipHistory: true });
    setChatBusy(true);

    try {
      const result = await callAiAssistant(text, action);
      typingNode?.remove();
      appendMessage(result.reply || fallbackAnswer(text), 'bot');
    } catch (error) {
      typingNode?.remove();
      appendMessage(fallbackAnswer(text), 'bot');
      console.error(error);
    } finally {
      setChatBusy(false);
      chatInput?.focus();
    }
  };

  const finalizeConversation = async (mode = 'email') => {
    const label = mode === 'whatsapp' ? 'Continuar por WhatsApp' : 'Enviar conversación al equipo';
    appendMessage(label, 'user');
    const typingNode = appendMessage(mode === 'whatsapp' ? 'Preparando derivación...' : 'Enviando conversación completa...', 'bot', { typing: true, skipHistory: true });
    setChatBusy(true);

    try {
      const action = mode === 'whatsapp' ? 'handoff' : 'finalize';
      const message = mode === 'whatsapp'
        ? 'El cliente solicita continuar por WhatsApp. Enviar la conversación completa por email antes de derivar.'
        : 'El cliente finaliza la consulta y solicita enviar la conversación completa por email a Ligna Milano.';
      const result = await callAiAssistant(message, action);
      typingNode?.remove();
      appendMessage(result.reply || 'Listo, he derivado la conversación completa al equipo de Ligna Milano para su revisión.', 'bot');
    } catch (error) {
      typingNode?.remove();
      appendMessage('Tengo el contexto preparado, pero no pude confirmar el envío por email desde el servidor. Puedes continuar por WhatsApp y el resumen irá incluido en el mensaje.', 'bot');
      console.error(error);
    } finally {
      setChatBusy(false);
      refreshWhatsAppLink();
      if (mode === 'whatsapp' && whatsappHandoff?.href) {
        window.open(whatsappHandoff.href, '_blank', 'noopener');
      } else {
        chatInput?.focus();
      }
    }
  };

  if (config.externalChatScriptUrl) {
    const script = document.createElement('script');
    script.src = config.externalChatScriptUrl;
    script.async = true;
    document.body.appendChild(script);
    if (config.enableNativeChatbot === false) {
      chatLauncher?.setAttribute('hidden', '');
      chatbot?.setAttribute('hidden', '');
    }
  }

  const openChatbot = () => {
    chatbot?.removeAttribute('hidden');
    chatLauncher?.setAttribute('hidden', '');
    setTimeout(() => chatInput?.focus(), 120);
  };

  const closeChatbot = () => {
    chatbot?.setAttribute('hidden', '');
    chatLauncher?.removeAttribute('hidden');
  };

  chatLauncher?.addEventListener('click', openChatbot);
  chatClose?.addEventListener('click', closeChatbot);

  chatbot?.addEventListener('click', event => {
    const option = event.target.closest('[data-chat-option]');
    if (option) {
      processChatMessage(option.textContent.trim());
      return;
    }

    const handoff = event.target.closest('[data-human-handoff]');
    if (handoff) finalizeConversation('whatsapp');
  });

  chatForm?.addEventListener('submit', event => {
    event.preventDefault();
    const text = chatInput?.value.trim();
    if (!text) return;
    chatInput.value = '';
    processChatMessage(text);
  });

  emailHandoff?.addEventListener('click', () => finalizeConversation('email'));

  whatsappHandoff?.addEventListener('click', event => {
    event.preventDefault();
    finalizeConversation('whatsapp');
  });

  refreshWhatsAppLink();
})();
