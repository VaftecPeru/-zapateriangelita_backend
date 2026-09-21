package pe.vaftec.angelita.payment.api;

import jakarta.validation.Valid;
import jakarta.validation.constraints.*;
import java.math.BigDecimal;
import java.util.Map;

public final class PaymentDtos {
    private PaymentDtos() {}

    public record Customer(
        @NotBlank String name,
        @Email @NotBlank String email,
        @NotBlank String phone
    ) {}

    public record ChargeRequest(
        @NotNull Long order_id,
        @NotBlank String order_code,
        @NotBlank String reference,
        @NotNull @DecimalMin("0.01") BigDecimal amount,
        @NotBlank @Size(min = 3, max = 3) String currency,
        @NotBlank String token_id,
        @NotBlank String device_session_id,
        @NotBlank String redirect_url,
        boolean guest_checkout,
        @Valid @NotNull Customer customer
    ) {}

    public record VerifyRequest(
        @NotNull Long order_id,
        @NotBlank String order_code,
        @NotBlank String reference,
        @NotBlank String transaction_id,
        @NotNull @DecimalMin("0.01") BigDecimal amount,
        @NotBlank @Size(min = 3, max = 3) String currency,
        boolean guest_checkout,
        boolean session_recovered
    ) {}

    public record PaymentResult(int httpStatus, Map<String, Object> body) {}
}
