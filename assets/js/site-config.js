// Configuración operativa de Ligna Milano
// El formulario usa send-contact.php y el chatbot IA usa chat-ai.php.
// La clave de OpenAI NO se coloca aquí. Va en el servidor, en config.local.php o variable de entorno.
window.LIGNA_SITE_CONFIG = {
  formEndpoint: "send-contact.php",
  chatEndpoint: "chat-ai.php",
  whatsappNumber: "34615304350",
  whatsappDefaultMessage: "Hola Ligna Milano, vengo desde la web y quiero consultar por un proyecto personalizado.",
  externalChatScriptUrl: "",
  enableNativeChatbot: true
};
