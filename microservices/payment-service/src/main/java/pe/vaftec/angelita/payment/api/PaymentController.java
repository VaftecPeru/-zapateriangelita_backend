package pe.vaftec.angelita.payment.api;

import jakarta.validation.Valid;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.*;
import pe.vaftec.angelita.payment.service.PaymentOrchestrator;

@RestController
@RequestMapping("/api/v1/payments")
public class PaymentController {
    private final PaymentOrchestrator orchestrator;
    private final String internalToken;

    public PaymentController(
        PaymentOrchestrator orchestrator,
        @Value("${payment.shared-secret}") String internalToken
    ) {
        this.orchestrator = orchestrator;
        this.internalToken = internalToken;
    }

    @PostMapping("/charge")
    public ResponseEntity<?> charge(
        @RequestHeader(value = "X-Internal-Token", defaultValue = "") String token,
        @Valid @RequestBody PaymentDtos.ChargeRequest request
    ) {
        requireInternalToken(token);
        PaymentDtos.PaymentResult result = orchestrator.charge(request);
        return ResponseEntity.status(result.httpStatus()).body(result.body());
    }

    @PostMapping("/verify")
    public ResponseEntity<?> verify(
        @RequestHeader(value = "X-Internal-Token", defaultValue = "") String token,
        @Valid @RequestBody PaymentDtos.VerifyRequest request
    ) {
        requireInternalToken(token);
        PaymentDtos.PaymentResult result = orchestrator.verify(request);
        return ResponseEntity.status(result.httpStatus()).body(result.body());
    }

    private void requireInternalToken(String token) {
        if (internalToken == null || internalToken.isBlank() || !constantTimeEquals(internalToken, token)) {
            throw new org.springframework.web.server.ResponseStatusException(
                org.springframework.http.HttpStatus.UNAUTHORIZED, "Invalid internal token"
            );
        }
    }

    private boolean constantTimeEquals(String expected, String actual) {
        byte[] a = expected.getBytes(java.nio.charset.StandardCharsets.UTF_8);
        byte[] b = actual.getBytes(java.nio.charset.StandardCharsets.UTF_8);
        return java.security.MessageDigest.isEqual(a, b);
    }
}
