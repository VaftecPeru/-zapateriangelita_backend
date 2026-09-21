package pe.vaftec.angelita.payment.service;

import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.http.MediaType;
import org.springframework.http.client.SimpleClientHttpRequestFactory;
import org.springframework.stereotype.Component;
import org.springframework.web.client.RestClient;
import org.springframework.web.client.RestClientResponseException;
import pe.vaftec.angelita.payment.api.PaymentDtos;

import java.time.Duration;
import java.util.LinkedHashMap;
import java.util.Map;

@Component
public class OpenpayClient {
    public record ProviderResponse(int status, JsonNode body) {}

    private final RestClient client;
    private final ObjectMapper mapper;
    private final String merchantId;

    public OpenpayClient(
        ObjectMapper mapper,
        @Value("${openpay.base-url}") String baseUrl,
        @Value("${openpay.merchant-id}") String merchantId,
        @Value("${openpay.private-key}") String privateKey
    ) {
        this.mapper = mapper;
        this.merchantId = merchantId;
        SimpleClientHttpRequestFactory factory = new SimpleClientHttpRequestFactory();
        factory.setConnectTimeout(Duration.ofSeconds(5));
        factory.setReadTimeout(Duration.ofSeconds(15));
        this.client = RestClient.builder()
            .baseUrl(baseUrl)
            .requestFactory(factory)
            .defaultHeaders(h -> h.setBasicAuth(privateKey, ""))
            .build();
    }

    public ProviderResponse createCharge(PaymentDtos.ChargeRequest request) {
        String[] names = request.customer().name().trim().split("\\s+", 2);
        Map<String, Object> customer = new LinkedHashMap<>();
        customer.put("name", names[0]);
        customer.put("last_name", names.length > 1 ? names[1] : "Angelita");
        customer.put("phone_number", request.customer().phone());
        customer.put("email", request.customer().email());

        Map<String, Object> payload = new LinkedHashMap<>();
        payload.put("method", "card");
        payload.put("source_id", request.token_id());
        payload.put("amount", request.amount());
        payload.put("currency", request.currency());
        payload.put("description", "Compra Zapatería Angelita " + request.order_code());
        payload.put("order_id", request.reference());
        payload.put("device_session_id", request.device_session_id());
        payload.put("use_3d_secure", true);
        payload.put("capture", true);
        payload.put("redirect_url", request.redirect_url());
        payload.put("customer", customer);

        return exchangePost("/v1/" + merchantId + "/charges", payload);
    }

    public ProviderResponse getCharge(String transactionId) {
        try {
            JsonNode body = client.get()
                .uri("/v1/{merchant}/charges/{id}", merchantId, transactionId)
                .accept(MediaType.APPLICATION_JSON)
                .retrieve()
                .body(JsonNode.class);
            return new ProviderResponse(200, body);
        } catch (RestClientResponseException e) {
            return new ProviderResponse(e.getStatusCode().value(), parse(e.getResponseBodyAsString()));
        }
    }

    public ProviderResponse findByReference(String reference) {
        try {
            JsonNode body = client.get()
                .uri(builder -> builder.path("/v1/{merchant}/charges")
                    .queryParam("order_id", reference)
                    .queryParam("limit", 10)
                    .build(merchantId))
                .accept(MediaType.APPLICATION_JSON)
                .retrieve()
                .body(JsonNode.class);
            return new ProviderResponse(200, body);
        } catch (RestClientResponseException e) {
            return new ProviderResponse(e.getStatusCode().value(), parse(e.getResponseBodyAsString()));
        }
    }

    private ProviderResponse exchangePost(String path, Object payload) {
        try {
            JsonNode body = client.post()
                .uri(path)
                .contentType(MediaType.APPLICATION_JSON)
                .accept(MediaType.APPLICATION_JSON)
                .body(payload)
                .retrieve()
                .body(JsonNode.class);
            return new ProviderResponse(200, body);
        } catch (RestClientResponseException e) {
            return new ProviderResponse(e.getStatusCode().value(), parse(e.getResponseBodyAsString()));
        }
    }

    private JsonNode parse(String body) {
        try { return mapper.readTree(body); }
        catch (Exception ignored) { return mapper.createObjectNode(); }
    }
}
