import { createSlice, PayloadAction } from '@reduxjs/toolkit';

interface Order {
  id: string;
  name: string;
  address: string;
  phoneNumber: string;
  turkeyQuantity: number;
  hamQuantity: number;
  comboQuantity: number;
  donationAmount: number;
  totalAmount: number;
  paymentMethod: 'cash' | 'check';
}

interface OrdersState {
  orders: Order[];
  isSyncing: boolean;
}

const initialState: OrdersState = {
  orders: [],
  isSyncing: false,
};

const ordersSlice = createSlice({
  name: 'orders',
  initialState,
  reducers: {
    addOrder(state, action: PayloadAction<Order>) {
      state.orders.push(action.payload);
    },
    updateOrder(state, action: PayloadAction<Order>) {
      const index = state.orders.findIndex(order => order.id === action.payload.id);
      if (index !== -1) {
        state.orders[index] = action.payload;
      }
    },
    removeOrder(state, action: PayloadAction<string>) {
      state.orders = state.orders.filter(order => order.id !== action.payload);
    },
    setSyncing(state, action: PayloadAction<boolean>) {
      state.isSyncing = action.payload;
    },
    clearOrders(state) {
      state.orders = [];
    },
  },
});

export const { addOrder, updateOrder, removeOrder, setSyncing, clearOrders } = ordersSlice.actions;

export default ordersSlice.reducer;