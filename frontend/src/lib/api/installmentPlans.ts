import { apiClient } from '../api';

export interface InstallmentPlan {
  id: number;
  invoice_id: number;
  plan_name: string | null;
  total_installments: number;
  total_amount: number;
  installment_amount: number;
  frequency: 'daily' | 'weekly' | 'biweekly' | 'monthly' | 'quarterly' | 'custom';
  frequency_days: number | null;
  start_date: string;
  end_date: string | null;
  status: 'active' | 'completed' | 'cancelled' | 'paused';
  payment_method_id: number | null;
  auto_charge: boolean;
  notes: string | null;
  metadata: any;
  created_at: string;
  updated_at: string;
  paid_installments_count?: number;
  pending_installments_count?: number;
  overdue_installments_count?: number;
  total_paid?: number;
  remaining_amount?: number;
  progress_percentage?: number;
  is_completed?: boolean;
  installment_payments?: InstallmentPayment[];
  payment_method?: any;
}

export interface InstallmentPayment {
  id: number;
  installment_plan_id: number;
  invoice_id: number;
  installment_number: number;
  amount: number;
  due_date: string;
  paid_date: string | null;
  status: 'pending' | 'paid' | 'overdue' | 'failed' | 'skipped';
  payment_id: number | null;
  notes: string | null;
  metadata: any;
  created_at: string;
  updated_at: string;
  payment?: any;
  is_overdue?: boolean;
  is_due?: boolean;
  days_until_due?: number | null;
}

export interface CreateInstallmentPlanData {
  plan_name?: string;
  total_installments: number;
  installment_amount?: number;
  frequency: 'daily' | 'weekly' | 'biweekly' | 'monthly' | 'quarterly' | 'custom';
  frequency_days?: number;
  start_date: string;
  payment_method_id?: number;
  auto_charge?: boolean;
  notes?: string;
}

export interface UpdateInstallmentPlanData {
  plan_name?: string;
  payment_method_id?: number;
  auto_charge?: boolean;
  notes?: string;
}

export const installmentPlansApi = {
  /**
   * Get installment plan for an invoice
   * Returns null if no installment plan exists (404)
   */
  async getByInvoice(invoiceId: number): Promise<InstallmentPlan | null> {
    try {
      const response = await apiClient.get(`/invoices/${invoiceId}/installment-plan`);
      
      // If response has data, return it
      if (response.data && !response.errors) {
        return response.data;
      }
      
      // Check if response has errors (404 is expected when no plan exists)
      if (response.errors || response.message) {
        const message = (response.message || '').toLowerCase();
        const hasErrors = response.errors && Object.keys(response.errors).length > 0;
        
        // Check if this is a "not found" error (404) - return null silently
        if (message.includes('no installment plan found') || 
            message.includes('not found') ||
            message.includes('404')) {
          return null;
        }
        
        // Check errors array for "not found" messages
        if (hasErrors) {
          const errorMessages = Object.values(response.errors).flat();
          const hasNotFoundError = errorMessages.some((msg: any) => {
            const msgStr = String(msg).toLowerCase();
            return msgStr.includes('no installment plan found') || 
                   msgStr.includes('not found') || 
                   msgStr.includes('404');
          });
          
          if (hasNotFoundError) {
            return null;
          }
        }
        
        // For other errors, throw
        throw new Error(response.message || 'Failed to get installment plan');
      }
      
      // Return the data if successful (fallback)
      return response.data || response;
    } catch (error: any) {
      // Handle 404 responses from API client or network errors
      const errorMessage = String(error?.message || error?.response?.data?.message || '').toLowerCase();
      
      if (errorMessage.includes('no installment plan found') || 
          errorMessage.includes('not found') ||
          errorMessage.includes('404') ||
          error?.response?.status === 404) {
        return null;
      }
      
      // Re-throw other errors
      throw error;
    }
  },

  /**
   * Create an installment plan for an invoice
   */
  async create(invoiceId: number, data: CreateInstallmentPlanData): Promise<InstallmentPlan> {
    const response = await apiClient.post(`/invoices/${invoiceId}/installment-plan`, data);
    if (response.errors) {
      throw new Error(response.message || 'Failed to create installment plan');
    }
    return response.data?.data || response.data;
  },

  /**
   * Update an installment plan
   */
  async update(planId: number, data: UpdateInstallmentPlanData): Promise<InstallmentPlan> {
    const response = await apiClient.put(`/installment-plans/${planId}`, data);
    return response.data.data || response.data;
  },

  /**
   * Cancel an installment plan
   */
  async cancel(planId: number): Promise<InstallmentPlan> {
    const response = await apiClient.post(`/installment-plans/${planId}/cancel`);
    return response.data.data || response.data;
  },

  /**
   * Pause an installment plan
   */
  async pause(planId: number): Promise<InstallmentPlan> {
    const response = await apiClient.post(`/installment-plans/${planId}/pause`);
    return response.data.data || response.data;
  },

  /**
   * Resume a paused installment plan
   */
  async resume(planId: number): Promise<InstallmentPlan> {
    const response = await apiClient.post(`/installment-plans/${planId}/resume`);
    return response.data.data || response.data;
  },

  /**
   * Get next due installment for an invoice
   */
  async getNextDue(invoiceId: number): Promise<InstallmentPayment> {
    const response = await apiClient.get(`/invoices/${invoiceId}/installment-plan/next-due`);
    return response.data;
  },
};

