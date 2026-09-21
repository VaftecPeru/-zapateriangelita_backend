package pe.vaftec.angelita.payment.service;

import org.springframework.stereotype.Component;
import java.util.Map;

@Component
public class PaymentErrorMapper {
    public record FriendlyError(String key, String title, String message) {}

    private static final Map<String, FriendlyError> ERRORS = Map.ofEntries(
        Map.entry("3001", new FriendlyError("CARD_DECLINED", "Tarjeta rechazada", "La tarjeta fue rechazada.")),
        Map.entry("3002", new FriendlyError("CARD_EXPIRED", "Tarjeta expirada", "La tarjeta ha expirado.")),
        Map.entry("3003", new FriendlyError("INSUFFICIENT_FUNDS", "Fondos insuficientes", "La tarjeta no tiene fondos suficientes.")),
        Map.entry("3004", new FriendlyError("CARD_DECLINED", "Tarjeta rechazada", "La tarjeta fue rechazada.")),
        Map.entry("3005", new FriendlyError("CARD_DECLINED", "Tarjeta rechazada", "La tarjeta fue rechazada.")),
        Map.entry("3006", new FriendlyError("OPERATION_NOT_ALLOWED", "Operación no permitida", "La operación no está permitida para esta tarjeta.")),
        Map.entry("3007", new FriendlyError("CARD_DECLINED", "Tarjeta rechazada", "La tarjeta fue rechazada.")),
        Map.entry("3008", new FriendlyError("ONLINE_PURCHASE_NOT_ALLOWED", "Tarjeta no compatible", "La tarjeta no permite compras en línea.")),
        Map.entry("3009", new FriendlyError("CARD_NOT_AUTHORIZED", "Tarjeta no autorizada", "El banco no autorizó esta tarjeta.")),
        Map.entry("3010", new FriendlyError("CARD_RESTRICTED", "Tarjeta restringida", "El banco ha restringido la tarjeta.")),
        Map.entry("3011", new FriendlyError("PAYMENT_NOT_AUTHORIZED", "Pago no autorizado", "El banco no autorizó el pago."))
    );

    public FriendlyError map(String code) {
        return ERRORS.getOrDefault(code, new FriendlyError(
            "PAYMENT_DECLINED", "Transacción no aprobada", "Openpay no aprobó el pago."
        ));
    }
}
