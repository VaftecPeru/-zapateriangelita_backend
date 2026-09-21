package pe.vaftec.angelita.payment.service;

import com.fasterxml.jackson.core.type.TypeReference;
import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;
import org.springframework.stereotype.Service;
import pe.vaftec.angelita.payment.api.PaymentDtos;
import pe.vaftec.angelita.payment.domain.PaymentAttempt;
import pe.vaftec.angelita.payment.domain.PaymentAttemptRepository;
import pe.vaftec.angelita.payment.domain.PaymentStatus;

import java.math.BigDecimal;
import java.util.*;

@Service
public class PaymentOrchestrator {
    private final PaymentAttemptRepository repository;
    private final OpenpayClient openpay;
    private final LaravelClient laravel;
    private final NotificationService notifications;
    private final PaymentErrorMapper errors;
    private final ObjectMapper mapper;

    public PaymentOrchestrator(
        PaymentAttemptRepository repository,
        OpenpayClient openpay,
        LaravelClient laravel,
        NotificationService notifications,
        PaymentErrorMapper errors,
        ObjectMapper mapper
    ) {
        this.repository = repository;
        this.openpay = openpay;
        this.laravel = laravel;
        this.notifications = notifications;
        this.errors = errors;
        this.mapper = mapper;
    }

    public PaymentDtos.PaymentResult charge(PaymentDtos.ChargeRequest request) {
        PaymentAttempt attempt = repository.findByReference(request.reference())
            .orElseGet(() -> repository.save(PaymentAttempt.create(
                request.order_id(), request.order_code(), request.reference(),
                request.amount(), request.currency().toUpperCase(Locale.ROOT), request.guest_checkout()
            )));

        if (!matches(attempt, request.amount(), request.currency(), request.order_id())) {
            return conflict("La referencia de pago ya existe con datos distintos.");
        }

        if (attempt.getTransactionId() != null && !attempt.getTransactionId().isBlank()) {
            return reconcile(attempt, openpay.getCharge(attempt.getTransactionId()));
        }

        // Si un request anterior quedó UNKNOWN antes de guardar transaction_id,
        // primero buscamos por order_id/referencia para evitar un doble cargo.
        OpenpayClient.ProviderResponse previous = openpay.findByReference(attempt.getReference());
        JsonNode recovered = findMatchingCharge(previous.body(), attempt);
        if (previous.status() >= 200 && previous.status() < 300 && recovered != null) {
            return handleProvider(attempt, new OpenpayClient.ProviderResponse(200, recovered));
        }

        attempt.setStatus(PaymentStatus.CHARGE_REQUESTED);
        repository.save(attempt);

        OpenpayClient.ProviderResponse provider = openpay.createCharge(request);
        if (provider.status() >= 500 || provider.status() == 408 || provider.status() == 409 || provider.status() == 429) {
            attempt.setStatus(PaymentStatus.UNKNOWN);
            attempt.setErrorMessage("Resultado de Openpay incierto.");
            repository.save(attempt);
            return result(502, false, "processing", attempt, Map.of(
                "stage", "charge",
                "message", "No se pudo confirmar el resultado. No repitas el cobro hasta verificarlo."
            ));
        }

        return handleProvider(attempt, provider);
    }

    public PaymentDtos.PaymentResult verify(PaymentDtos.VerifyRequest request) {
        PaymentAttempt attempt = repository.findByTransactionId(request.transaction_id())
            .or(() -> repository.findByReference(request.reference()))
            .orElseGet(() -> {
                PaymentAttempt recovered = PaymentAttempt.create(
                    request.order_id(), request.order_code(), request.reference(),
                    request.amount(), request.currency().toUpperCase(Locale.ROOT), request.guest_checkout()
                );
                recovered.setTransactionId(request.transaction_id());
                recovered.setStatus(PaymentStatus.PROCESSING);
                return repository.save(recovered);
            });

        if (!Objects.equals(attempt.getTransactionId(), request.transaction_id())) {
            attempt.setTransactionId(request.transaction_id());
            repository.save(attempt);
        }

        if (!matches(attempt, request.amount(), request.currency(), request.order_id())) {
            return conflict("La transacción no coincide con el pedido.");
        }

        PaymentDtos.PaymentResult result = reconcile(attempt, openpay.getCharge(request.transaction_id()));
        if (request.session_recovered() && "paid".equals(result.body().get("payment_status"))) {
            Map<String, Object> safe = new LinkedHashMap<>(result.body());
            safe.remove("auth_token");
            safe.remove("user");
            safe.put("requires_login", true);
            safe.put("session_recovered", true);
            return new PaymentDtos.PaymentResult(result.httpStatus(), safe);
        }
        return result;
    }

    public Map<String, Object> webhook(JsonNode webhook) {
        if ("verification".equals(webhook.path("type").asText())) {
            return Map.of("received", true, "type", "verification");
        }

        JsonNode tx = webhook.path("transaction");
        String transactionId = tx.path("id").asText("");
        String reference = tx.path("order_id").asText("");
        if (transactionId.isBlank() && reference.isBlank()) {
            return Map.of("received", true, "reconciled", false);
        }

        Optional<PaymentAttempt> found = !transactionId.isBlank()
            ? repository.findByTransactionId(transactionId)
            : repository.findByReference(reference);

        if (found.isEmpty()) {
            return Map.of("received", true, "reconciled", false);
        }

        PaymentAttempt attempt = found.get();
        if (!transactionId.isBlank() && (attempt.getTransactionId() == null || attempt.getTransactionId().isBlank())) {
            attempt.setTransactionId(transactionId);
            repository.save(attempt);
        }

        OpenpayClient.ProviderResponse provider = openpay.getCharge(attempt.getTransactionId());
        PaymentDtos.PaymentResult result = reconcile(attempt, provider);
        return Map.of(
            "received", true,
            "reconciled", "paid".equals(result.body().get("payment_status")) || "failed".equals(result.body().get("payment_status")),
            "payment_status", result.body().getOrDefault("payment_status", "processing")
        );
    }

    private PaymentDtos.PaymentResult reconcile(PaymentAttempt attempt, OpenpayClient.ProviderResponse provider) {
        if (provider.status() < 200 || provider.status() >= 300 || provider.body() == null) {
            return result(502, false, "processing", attempt, Map.of(
                "message", "No fue posible consultar el pago en Openpay."
            ));
        }
        return handleProvider(attempt, provider);
    }

    private PaymentDtos.PaymentResult handleProvider(PaymentAttempt attempt, OpenpayClient.ProviderResponse provider) {
        JsonNode body = provider.body();
        if (provider.status() < 200 || provider.status() >= 300) {
            String code = body.path("error_code").asText("");
            PaymentErrorMapper.FriendlyError friendly = errors.map(code);
            attempt.setStatus(PaymentStatus.FAILED);
            attempt.setErrorCode(code);
            attempt.setErrorMessage(friendly.message());
            repository.save(attempt);
            safeFailCallback(attempt, friendly.message());
            return result(422, false, "failed", attempt, Map.of(
                "stage", "charge",
                "error_code", code,
                "error_key", friendly.key(),
                "error_title", friendly.title(),
                "message", friendly.message()
            ));
        }

        if (!providerMatches(body, attempt)) {
            attempt.setStatus(PaymentStatus.UNKNOWN);
            attempt.setErrorMessage("Respuesta Openpay no coincide con pedido.");
            repository.save(attempt);
            return result(409, false, "processing", attempt, Map.of(
                "message", "Los datos de la transacción requieren revisión."
            ));
        }

        attempt.setTransactionId(body.path("id").asText(attempt.getTransactionId()));
        String openpayStatus = body.path("status").asText("").toLowerCase(Locale.ROOT);
        attempt.setOpenpayStatus(openpayStatus);

        JsonNode method = body.path("payment_method");
        String redirect = method.path("url").asText("");
        if (!redirect.isBlank()) attempt.setRedirectUrl(redirect);

        if ("completed".equals(openpayStatus)) {
            attempt.setStatus(PaymentStatus.COMPLETED);
            repository.save(attempt);
            return complete(attempt);
        }

        if (Set.of("failed", "cancelled").contains(openpayStatus)) {
            String providerMessage = body.path("error_message").asText(body.path("description").asText("El pago no fue aprobado."));
            String code = body.path("error_code").asText("");
            PaymentErrorMapper.FriendlyError friendly = errors.map(code);
            attempt.setStatus(PaymentStatus.FAILED);
            attempt.setErrorCode(code);
            attempt.setErrorMessage(providerMessage);
            repository.save(attempt);
            safeFailCallback(attempt, providerMessage);
            return result(422, false, "failed", attempt, Map.of(
                "error_code", code,
                "error_key", friendly.key(),
                "error_title", friendly.title(),
                "message", friendly.message()
            ));
        }

        boolean requiresRedirect = !redirect.isBlank() || "redirect".equalsIgnoreCase(method.path("type").asText(""));
        attempt.setStatus(requiresRedirect ? PaymentStatus.REQUIRES_3DS : PaymentStatus.PROCESSING);
        repository.save(attempt);

        Map<String, Object> extra = new LinkedHashMap<>();
        extra.put("message", requiresRedirect
            ? "Tarjeta válida. Continúa con la verificación 3D Secure."
            : "El pago está siendo procesado por Openpay.");
        extra.put("requires_redirect", requiresRedirect);
        if (requiresRedirect) extra.put("redirect_url", redirect);
        return result(202, true, "processing", attempt, extra);
    }

    private PaymentDtos.PaymentResult complete(PaymentAttempt attempt) {
        Map<String, Object> callbackPayload = baseCallback(attempt);
        JsonNode callback = laravel.complete(attempt.getOrderId(), callbackPayload);

        if (!attempt.isNotificationSent()) {
            try {
                notifications.sendPurchaseNotifications(callback);
                attempt.setNotificationSent(true);
                attempt.setVoucherGenerated(true);
                repository.save(attempt);
            } catch (Exception ignored) {
                // El pago no debe revertirse por una falla SMTP. Queda auditable
                // con notification_sent=false para reintento operativo.
            }
        }

        Map<String, Object> body = mapper.convertValue(callback, new TypeReference<>() {});
        body.put("ok", true);
        body.put("payment_status", "paid");
        body.put("openpay_status", attempt.getOpenpayStatus());
        body.put("transaction_id", attempt.getTransactionId());
        body.put("order_id", attempt.getOrderId());
        return new PaymentDtos.PaymentResult(200, body);
    }

    private void safeFailCallback(PaymentAttempt attempt, String message) {
        try {
            Map<String, Object> payload = baseCallback(attempt);
            payload.put("message", message);
            laravel.fail(attempt.getOrderId(), payload);
        } catch (Exception ignored) {
            // Laravel también libera idempotentemente al recibir la respuesta proxy.
        }
    }

    private Map<String, Object> baseCallback(PaymentAttempt attempt) {
        Map<String, Object> payload = new LinkedHashMap<>();
        payload.put("reference", attempt.getReference());
        payload.put("transaction_id", attempt.getTransactionId());
        payload.put("amount", attempt.getAmount());
        payload.put("currency", attempt.getCurrency());
        payload.put("guest_checkout", attempt.isGuestCheckout());
        return payload;
    }

    private JsonNode findMatchingCharge(JsonNode body, PaymentAttempt attempt) {
        if (body == null) return null;
        JsonNode list = body.has("data") ? body.path("data") : body;
        if (!list.isArray()) return null;
        for (JsonNode charge : list) {
            if (providerMatches(charge, attempt)) return charge;
        }
        return null;
    }

    private boolean providerMatches(JsonNode body, PaymentAttempt attempt) {
        if (body == null) return false;
        String ref = body.path("order_id").asText("");
        String currency = body.path("currency").asText("").toUpperCase(Locale.ROOT);
        BigDecimal amount = body.path("amount").decimalValue();
        return attempt.getReference().equals(ref)
            && attempt.getCurrency().equalsIgnoreCase(currency)
            && attempt.getAmount().setScale(2).compareTo(amount.setScale(2)) == 0
            && !body.path("id").asText("").isBlank();
    }

    private boolean matches(PaymentAttempt attempt, BigDecimal amount, String currency, Long orderId) {
        return Objects.equals(attempt.getOrderId(), orderId)
            && attempt.getCurrency().equalsIgnoreCase(currency)
            && attempt.getAmount().setScale(2).compareTo(amount.setScale(2)) == 0;
    }

    private PaymentDtos.PaymentResult conflict(String message) {
        return new PaymentDtos.PaymentResult(409, Map.of(
            "ok", false,
            "payment_status", "processing",
            "message", message
        ));
    }

    private PaymentDtos.PaymentResult result(int http, boolean ok, String paymentStatus, PaymentAttempt attempt, Map<String, Object> extra) {
        Map<String, Object> body = new LinkedHashMap<>();
        body.put("ok", ok);
        body.put("payment_status", paymentStatus);
        body.put("order_id", attempt.getOrderId());
        body.put("order_code", attempt.getOrderCode());
        if (attempt.getTransactionId() != null) body.put("transaction_id", attempt.getTransactionId());
        if (attempt.getOpenpayStatus() != null) body.put("openpay_status", attempt.getOpenpayStatus());
        body.putAll(extra);
        return new PaymentDtos.PaymentResult(http, body);
    }
}
