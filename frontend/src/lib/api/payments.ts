/**
 * Payments API
 */

import { apiClient, ApiResponse, PaginatedResponse } from '../api';

export interface Payment {
  id: number;
  payment_reference: string;
  invoice_id: number;
  user_id: number;
  payment_method_id: number;
  amount: number;
  status: 'pending' | 'processing' | 'completed' | 'failed' | 'refunded';
  payment_type: 'full' | 'partial';
  gateway: string;
  gateway_transaction_id?: string;
  gateway_response?: any;
  processed_at?: string;
  notes?: string;
  created_at: string;
  updated_at: string;
  invoice?: any;
  user?: any;
  payment_method?: any;
}

export interface CreatePaymentData {
  invoice_id: number;
  payment_method_id: number;
  amount: number;
  payment_type: 'full' | 'partial';
  gateway?: string;
  notes?: string;
}

export const paymentsApi = {
  async getAll(params?: {
    page?: number;
    per_page?: number;
    status?: string;
    payment_method?: string;
    search?: string;
    date_from?: string;
    date_to?: string;
  }): Promise<ApiResponse<PaginatedResponse<Payment>>> {
    const queryParams = new URLSearchParams();
    if (params?.page) queryParams.append('page', params.page.toString());
    if (params?.per_page) queryParams.append('per_page', params.per_page.toString());
    if (params?.status) queryParams.append('status', params.status);
    if (params?.payment_method) queryParams.append('payment_method', params.payment_method);
    if (params?.search) queryParams.append('search', params.search);
    if (params?.date_from) queryParams.append('date_from', params.date_from);
    if (params?.date_to) queryParams.append('date_to', params.date_to);

    const query = queryParams.toString();
    return apiClient.get<PaginatedResponse<Payment>>(`/payments${query ? `?${query}` : ''}`);
  },

  async getById(id: number): Promise<ApiResponse<Payment>> {
    return apiClient.get<Payment>(`/payments/${id}`);
  },

  async create(data: CreatePaymentData): Promise<ApiResponse<Payment>> {
    return apiClient.post<Payment>('/payments', data);
  },

  async delete(id: number): Promise<ApiResponse> {
    return apiClient.delete(`/payments/${id}`);
  },

  async downloadReceipt(id: number): Promise<void> {
    return apiClient.download(`/payments/${id}/receipt`, `receipt-${id}.pdf`);
  },

  async track(id: number): Promise<ApiResponse<{
    payment: Payment;
    timeline: Array<{
      status: string;
      label: string;
      description: string;
      timestamp: string;
      completed: boolean;
      current?: boolean;
      refund?: boolean;
    }>;
    activity_logs: any[];
    estimated_completion?: string;
  }>> {
    return apiClient.get(`/payments/${id}/track`);
  },

  async process(id: number, data?: { gateway_transaction_id?: string }): Promise<ApiResponse<Payment>> {
    return apiClient.post<Payment>(`/payments/${id}/process`, data || {});
  },

  async getRedirectUrl(id: number, options?: { return_url?: string; cancel_url?: string }): Promise<ApiResponse<{ redirect_url: string; payment_id: number; message: string }>> {
    const queryParams = new URLSearchParams();
    if (options?.return_url) queryParams.append('return_url', options.return_url);
    if (options?.cancel_url) queryParams.append('cancel_url', options.cancel_url);
    
    const query = queryParams.toString();
    return apiClient.get<{ redirect_url: string; payment_id: number; message: string }>(`/payments/${id}/redirect-url${query ? `?${query}` : ''}`);
  },
};

