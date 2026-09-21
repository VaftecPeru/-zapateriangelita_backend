package pe.vaftec.angelita.payment.service;

import com.fasterxml.jackson.databind.JsonNode;
import jakarta.mail.internet.MimeMessage;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.mail.javamail.JavaMailSender;
import org.springframework.mail.javamail.MimeMessageHelper;
import org.springframework.stereotype.Service;

import java.nio.charset.StandardCharsets;

@Service
public class NotificationService {
    private final JavaMailSender mailSender;
    private final VoucherService voucherService;
    private final boolean enabled;
    private final String from;

    public NotificationService(
        JavaMailSender mailSender,
        VoucherService voucherService,
        @Value("${payment.notifications.enabled:true}") boolean enabled,
        @Value("${payment.mail.from}") String from
    ) {
        this.mailSender = mailSender;
        this.voucherService = voucherService;
        this.enabled = enabled;
        this.from = from;
    }

    public void sendPurchaseNotifications(JsonNode callback) {
        if (!enabled) return;
        JsonNode payload = callback.path("notification_payload");
        String email = payload.path("customer_email").asText("");
        if (email.isBlank()) return;

        byte[] voucher = voucherService.generate(payload);
        sendVoucher(email, payload, voucher);

        if (callback.path("account_created").asBoolean(false)) {
            String temporaryPassword = callback.path("temporary_password").asText("");
            if (!temporaryPassword.isBlank()) {
                sendCredentials(email, payload.path("customer_name").asText("Cliente"), temporaryPassword);
            }
        }
    }

    private void sendVoucher(String email, JsonNode payload, byte[] voucher) {
        try {
            MimeMessage message = mailSender.createMimeMessage();
            MimeMessageHelper helper = new MimeMessageHelper(message, true, StandardCharsets.UTF_8.name());
            helper.setFrom(from);
            helper.setTo(email);
            helper.setSubject("Compra confirmada " + payload.path("order_code").asText(""));
            helper.setText(
                "<h2>¡Pago confirmado!</h2>" +
                "<p>Recibimos correctamente tu pago.</p>" +
                "<p>Pedido: <strong>" + escape(payload.path("order_code").asText("")) + "</strong></p>" +
                "<p>Total: <strong>" + escape(payload.path("currency").asText("MXN")) + " " +
                escape(payload.path("total").asText("0.00")) + "</strong></p>" +
                "<p>Adjuntamos tu comprobante de compra.</p>",
                true
            );
            helper.addAttachment("voucher-" + payload.path("order_code").asText("compra") + ".pdf",
                () -> new java.io.ByteArrayInputStream(voucher));
            mailSender.send(message);
        } catch (Exception e) {
            throw new IllegalStateException("Could not send purchase email", e);
        }
    }

    private void sendCredentials(String email, String name, String password) {
        try {
            MimeMessage message = mailSender.createMimeMessage();
            MimeMessageHelper helper = new MimeMessageHelper(message, false, StandardCharsets.UTF_8.name());
            helper.setFrom(from);
            helper.setTo(email);
            helper.setSubject("Tu cuenta en Zapatería Angelita");
            helper.setText(
                "<h2>Bienvenido(a), " + escape(name) + "</h2>" +
                "<p>Tu cuenta de cliente fue creada después de confirmar la compra.</p>" +
                "<p>Usuario: <strong>" + escape(email) + "</strong></p>" +
                "<p>Contraseña temporal: <strong>" + escape(password) + "</strong></p>" +
                "<p>Al ingresar deberás crear una nueva contraseña personal.</p>",
                true
            );
            mailSender.send(message);
        } catch (Exception e) {
            throw new IllegalStateException("Could not send credentials email", e);
        }
    }

    private String escape(String value) {
        return value == null ? "" : value
            .replace("&", "&amp;")
            .replace("<", "&lt;")
            .replace(">", "&gt;")
            .replace(String.valueOf((char) 34), "&quot;");
    }
}
